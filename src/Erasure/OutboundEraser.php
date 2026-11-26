<?php

declare(strict_types=1);

namespace Qiick\Outbound\Erasure;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryConcurrencyException;
use Qiick\Outbound\Delivery\WebhookDeliveryStorageException;
use Qiick\Outbound\Events\SubjectErasedEvent;
use Qiick\Outbound\Models\WebhookDelivery;
use RuntimeException;
use SensitiveParameter;

/**
 * GDPR erasure of one person's data held by outbound (qiick fleet erasure
 * standard, `.qiick/standards/erasure.md`).
 *
 * Endpoints and the delivery log are the organisation's, so both modes
 * anonymise and nothing is deleted:
 *
 * - the person is unlinked from the endpoints they created
 *   (`created_by`), the redeliveries they asked for
 *   (`next_attempt_triggered_by`) and the attempts those sent
 *   (`triggered_by`);
 * - a delivery the product queued about them (`subject_public_id`, set at
 *   dispatch) loses its payload and the reference, and a pending one is
 *   cancelled, so it is never sent; the coordinator never records an attempt
 *   over a cancelled delivery, even one a worker claimed before the cancel.
 *
 * A delivery queued without a subject cannot be attributed: its payload stays
 * encrypted until `payload_expires_at` (30 days by default) and is then
 * pruned. Endpoint configuration (`name`, `url`, `headers`) is the
 * organisation's, not the person's.
 *
 * @api
 *
 * @phpstan-import-type StoreCounts from ErasureReport
 */
final readonly class OutboundEraser
{
    public const string DELETE = 'delete';

    public const string ANONYMISE = 'anonymise';

    public function __construct(private Dispatcher $events) {}

    /**
     * Side effects: one transaction on the package's connection (a savepoint
     * inside the caller's); after the outermost commit, one
     * {@see SubjectErasedEvent}. A dry run writes and dispatches nothing.
     *
     * @param  list<string>  $publicIds  the person's principal and API key public ids
     * @param  list<string>  $identifiers  unused: outbound keeps no free text about the person
     *
     * @throws InvalidArgumentException on an unknown mode, a public id that is
     *                                  not a UUID or a blank identifier
     * @throws OrganisationScopeUnsupportedException on an organisation-scoped run
     * @throws RuntimeException when the database refuses a statement
     */
    public function erase(
        #[SensitiveParameter] string $userId,
        #[SensitiveParameter] string $userPublicId,
        string $mode,
        #[SensitiveParameter] array $publicIds = [],
        #[SensitiveParameter] array $identifiers = [],
        ?string $organisationId = null,
        bool $dryRun = false,
    ): ErasureReport {
        $this->validate($userPublicId, $mode, $publicIds, $identifiers);

        if ($organisationId !== null) {
            throw new OrganisationScopeUnsupportedException('Outbound erasure is global: endpoints have no organisation.');
        }

        // The user columns are bigint foreign keys: a textual key matches no
        // row, and comparing it would let MySQL coerce it to another user's id.
        $userKey = ctype_digit($userId) && (string) (int) $userId === $userId ? (int) $userId : null;
        // Dispatch stores the subject in lower case: compare the same spelling.
        $subjectPublicIds = array_map(strtolower(...), [$userPublicId, ...$publicIds]);
        $connection = (new WebhookDelivery)->getConnection();

        try {
            /** @var ErasureReport $report Laravel 12 types transaction() as mixed. */
            $report = $connection->transaction(function () use ($connection, $mode, $dryRun, $userKey, $subjectPublicIds): ErasureReport {
                // Read before the deliveries lose their subject reference.
                $attemptCount = $this->eraseAttempts($connection, $userKey, $subjectPublicIds, $dryRun);
                $report = new ErasureReport('qiick/laravel-outbound', $mode, $dryRun, [
                    'webhook_endpoints' => $this->anonymised($this->unlinkEndpoints($connection, $userKey, $dryRun)),
                    'webhook_deliveries' => $this->anonymised($this->eraseDeliveries($connection, $userKey, $subjectPublicIds, $dryRun)),
                    'webhook_delivery_attempts' => $this->anonymised($attemptCount),
                ]);

                // Registered inside this transaction, so it waits for this
                // connection's commit and no other connection's.
                if (! $dryRun) {
                    $connection->afterCommit(fn () => $this->events->dispatch(new SubjectErasedEvent($report)));
                }

                return $report;
            });
        } catch (PDOException $databaseException) {
            // A QueryException or DeadlockException message interpolates its
            // bindings: the person's ids. A deadlock stays one, so the
            // product's transaction can retry.
            $sanitisedException = WebhookDeliveryStorageException::withoutBindings($databaseException);

            throw $sanitisedException instanceof WebhookDeliveryConcurrencyException
                ? $sanitisedException
                : new RuntimeException('Outbound erasure database operation failed.');
        }

        return $report;
    }

    /**
     * @return int the endpoints the person created, now unlinked
     */
    private function unlinkEndpoints(ConnectionInterface $connection, ?int $userKey, bool $isDryRun): int
    {
        if ($userKey === null) {
            return 0;
        }

        $endpointIds = $connection->table('webhook_endpoints')->where('created_by', $userKey)->lockForUpdate()->pluck('id')->all();

        if (! $isDryRun && $endpointIds !== []) {
            $connection->table('webhook_endpoints')->whereIn('id', $endpointIds)->update(['created_by' => null, 'updated_at' => CarbonImmutable::now()]);
        }

        return count($endpointIds);
    }

    /**
     * @param  non-empty-list<string>  $subjectPublicIds
     * @return int the deliveries about the person or redelivered at their request, each counted once
     */
    private function eraseDeliveries(ConnectionInterface $connection, ?int $userKey, array $subjectPublicIds, bool $isDryRun): int
    {
        $deliveryIds = $connection->table('webhook_deliveries')
            ->whereIn('subject_public_id', $subjectPublicIds)
            ->when($userKey !== null, fn ($deliveriesQuery) => $deliveriesQuery->orWhere('next_attempt_triggered_by', $userKey))
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        if ($isDryRun || $deliveryIds === []) {
            return count($deliveryIds);
        }

        $now = CarbonImmutable::now();
        $aboutThePerson = fn () => $connection->table('webhook_deliveries')->whereIn('id', $deliveryIds)->whereIn('subject_public_id', $subjectPublicIds);

        $aboutThePerson()->where('status', DeliveryStatus::Pending->value)->update([
            'status' => DeliveryStatus::Cancelled->value,
            'next_attempt_at' => null,
        ]);
        $aboutThePerson()->update(['payload' => null, 'subject_public_id' => null, 'updated_at' => $now]);

        if ($userKey !== null) {
            $connection->table('webhook_deliveries')->whereIn('id', $deliveryIds)->where('next_attempt_triggered_by', $userKey)
                ->update(['next_attempt_triggered_by' => null, 'updated_at' => $now]);
        }

        return count($deliveryIds);
    }

    /**
     * The attempts the person triggered lose the link; every attempt of a
     * delivery about them, whoever triggered it, loses what the receiver
     * answered (`response_headers`, `request_id`, `response_excerpt`): a
     * receiver may echo a customer reference in `X-Request-Id`.
     *
     * @param  non-empty-list<string>  $subjectPublicIds
     * @return int the attempts changed, each counted once
     */
    private function eraseAttempts(ConnectionInterface $connection, ?int $userKey, array $subjectPublicIds, bool $isDryRun): int
    {
        $aboutThePerson = $connection->table('webhook_deliveries')->whereIn('subject_public_id', $subjectPublicIds)->select('id');
        $attemptIds = $connection->table('webhook_delivery_attempts')
            ->whereIn('delivery_id', $aboutThePerson)
            ->when($userKey !== null, fn ($attemptsQuery) => $attemptsQuery->orWhere('triggered_by', $userKey))
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        if ($isDryRun || $attemptIds === []) {
            return count($attemptIds);
        }

        $connection->table('webhook_delivery_attempts')->whereIn('id', $attemptIds)->whereIn('delivery_id', $aboutThePerson)
            ->update(['response_headers' => null, 'request_id' => null, 'response_excerpt' => null]);

        if ($userKey !== null) {
            $connection->table('webhook_delivery_attempts')->whereIn('id', $attemptIds)->where('triggered_by', $userKey)->update(['triggered_by' => null]);
        }

        return count($attemptIds);
    }

    /**
     * @param  array<mixed>  $publicIds
     * @param  array<mixed>  $identifiers
     *
     * @throws InvalidArgumentException
     */
    private function validate(string $userPublicId, string $mode, array $publicIds, array $identifiers): void
    {
        if (! in_array($mode, [self::DELETE, self::ANONYMISE], true)) {
            throw new InvalidArgumentException('Unknown outbound erasure mode.');
        }

        foreach ([$userPublicId, ...$publicIds] as $publicId) {
            if (! is_string($publicId) || ! Str::isUuid($publicId)) {
                throw new InvalidArgumentException('Outbound erasure public ids must be UUIDs.');
            }
        }

        foreach ($identifiers as $identifier) {
            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException('Outbound erasure identifiers must be non-empty strings.');
            }
        }
    }

    /**
     * @return StoreCounts
     */
    private function anonymised(int $rowCount): array
    {
        return ['deleted' => 0, 'anonymised' => $rowCount, 'kept' => 0, 'reason' => null];
    }
}
