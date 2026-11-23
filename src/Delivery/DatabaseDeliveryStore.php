<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookSubscription;
use Qiick\Outbound\Webhooks\WebhookMessage;

/**
 * Reads and writes the delivery tables for {@see WebhookDeliveryCoordinator}.
 *
 * The tables are mandatory package tables (standards review R1), so this is
 * the one store: there is no storage interface to swap.
 *
 * Concurrency: a worker leases a delivery by setting `claimed_until`. The
 * per-endpoint limit counts the pending deliveries still leased, under a
 * lock on the endpoint row, so two workers cannot both take the last slot.
 * A crashed worker's lease simply expires. Query bindings are UTC, like
 * every date the package models store.
 *
 * @internal
 */
final class DatabaseDeliveryStore
{
    /**
     * @param  string|null  $scopeColumn  `outbound.webhooks.scope_column`
     */
    public function __construct(
        private readonly ?string $scopeColumn = null,
    ) {}

    /**
     * Active endpoints subscribed to the type, or to every type, in the scope.
     *
     * @return Collection<int, WebhookEndpoint>
     *
     * @throws InvalidArgumentException when a scope is given but no scope
     *                                  column is configured: the product
     *                                  would believe it scoped the fan-out
     */
    public function subscribedEndpoints(string $eventType, ?string $scope): Collection
    {
        $endpointsQuery = WebhookEndpoint::query()
            ->where('is_active', true)
            ->whereHas('subscriptions', function (Builder $subscriptionsQuery) use ($eventType): void {
                $subscriptionsQuery->whereIn('event_type', [$eventType, WebhookSubscription::ALL_EVENT_TYPES]);
            });

        if ($this->scopeColumn !== null) {
            $endpointsQuery->where($this->scopeColumn, $scope);
        } elseif ($scope !== null) {
            throw new InvalidArgumentException('A webhook scope needs outbound.webhooks.scope_column.');
        }

        return $endpointsQuery->orderBy('id')->get();
    }

    /**
     * A pending delivery, due now.
     *
     * @return WebhookDelivery|null null when the endpoint already has this event
     *
     * @throws WebhookDeliveryStorageException when the row cannot be written
     */
    public function createDelivery(WebhookEndpoint $endpoint, WebhookMessage $message, string $rawBody, int $maxAttempts): ?WebhookDelivery
    {
        $delivery = $endpoint->deliveries()->make([
            'event_id' => $message->id,
            'event_type' => $message->type,
            'occurred_at' => $message->timestamp,
            'payload_version' => $endpoint->payload_version,
            'max_attempts' => $maxAttempts,
        ])->usePayload($rawBody)->forceFill(['next_attempt_at' => CarbonImmutable::now()]);

        try {
            $delivery->save();
        } catch (DuplicateWebhookDeliveryException) {
            return null;
        }

        return $delivery;
    }

    /**
     * Ids of due, unleased deliveries of active endpoints, oldest first, at
     * most each endpoint's concurrency per endpoint: one endpoint's backlog
     * cannot fill the batch and starve every other endpoint (or tenant).
     * Nothing is leased here; {@see claim()} leases each one just before its
     * send.
     *
     * @return list<int>
     */
    public function dueCandidates(int $limit, int $defaultMaxConcurrency): array
    {
        $now = CarbonImmutable::now('UTC');

        // The endpoints whose oldest due delivery waits longest, at most `$limit` of them.
        $endpointsWithDueDeliveries = $this->claimable(WebhookDelivery::query(), $now)
            ->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.endpoint_id')
            ->where('webhook_endpoints.is_active', true)
            ->whereNull('webhook_endpoints.deleted_at')
            ->where('webhook_deliveries.next_attempt_at', '<=', $now)
            ->groupBy('webhook_deliveries.endpoint_id', 'webhook_endpoints.max_concurrency')
            ->select(['webhook_deliveries.endpoint_id', 'webhook_endpoints.max_concurrency'])
            ->selectRaw('min(next_attempt_at) as oldest_due_at')
            ->orderBy('oldest_due_at')
            ->orderBy('webhook_deliveries.endpoint_id')
            ->limit($limit)
            ->toBase()
            ->get();

        /** @var \Illuminate\Support\Collection<int, WebhookDelivery> $candidateDeliveries */
        $candidateDeliveries = new \Illuminate\Support\Collection;

        /** @var object{endpoint_id: int, max_concurrency: int|null} $endpointWithDueDeliveries */
        foreach ($endpointsWithDueDeliveries as $endpointWithDueDeliveries) {
            $candidateDeliveries->push(...$this->claimable(WebhookDelivery::query(), $now)
                ->where('endpoint_id', $endpointWithDueDeliveries->endpoint_id)
                ->where('next_attempt_at', '<=', $now)
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->limit($endpointWithDueDeliveries->max_concurrency ?? $defaultMaxConcurrency)
                ->get(['id', 'next_attempt_at']));
        }

        // Oldest first across endpoints, the id breaking ties. The stored UTC
        // "Y-m-d H:i:s" strings sort chronologically as they are.
        $orderedCandidates = $candidateDeliveries->all();
        usort($orderedCandidates, static fn (WebhookDelivery $first, WebhookDelivery $second): int => [$first->getRawOriginal('next_attempt_at'), $first->id] <=> [$second->getRawOriginal('next_attempt_at'), $second->id]);

        return array_map(static fn (WebhookDelivery $delivery): int => $delivery->id, array_slice($orderedCandidates, 0, $limit));
    }

    /**
     * Lease one delivery for one send, with its endpoint loaded, or null when
     * it is no longer due, another worker holds it, its endpoint is disabled
     * or deleted, or the endpoint has no free slot. The endpoint row is locked
     * while the slots are counted, so two workers cannot both take the last.
     */
    public function claim(int $deliveryId, int $leaseSeconds, int $defaultMaxConcurrency): ?WebhookDelivery
    {
        /** @var WebhookDelivery|null $claimedDelivery Laravel 12 types transaction() as mixed. */
        $claimedDelivery = WebhookDelivery::query()->getConnection()->transaction(function () use ($deliveryId, $leaseSeconds, $defaultMaxConcurrency): ?WebhookDelivery {
            $now = CarbonImmutable::now('UTC');
            $endpoint = WebhookEndpoint::query()
                ->whereKey(WebhookDelivery::query()->whereKey($deliveryId)->select('endpoint_id'))
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($endpoint === null) {
                return null;
            }

            $leasedCount = WebhookDelivery::query()
                ->where('endpoint_id', $endpoint->id)
                ->where('status', DeliveryStatus::Pending->value)
                ->where('claimed_until', '>', $now)
                ->count();

            if ($leasedCount >= ($endpoint->max_concurrency ?? $defaultMaxConcurrency)) {
                return null;
            }

            $leasedRows = $this->claimable(WebhookDelivery::query()->whereKey($deliveryId), $now)
                ->where('next_attempt_at', '<=', $now)
                ->update(['claimed_until' => $now->addSeconds($leaseSeconds)]);

            return $leasedRows === 1
                ? WebhookDelivery::query()->findOrFail($deliveryId)->setRelation('endpoint', $endpoint)
                : null;
        });

        return $claimedDelivery;
    }

    /**
     * Record one attempt and its outcome, and move the endpoint's failure
     * streak, in one transaction. Nothing is written when another worker has
     * already recorded this attempt (its lease had expired and the delivery
     * was taken again): the stored attempt count no longer matches.
     *
     * @param  array<string, mixed>  $attemptAttributes
     * @param  array<string, mixed>  $deliveryChanges
     * @param  bool  $isReceiverOutcome  false for a refusal on the package's side
     *                                   (signing, SSRF guard, internal error):
     *                                   it says nothing about the receiver, so
     *                                   the streak stays as it is
     * @return bool whether the attempt was recorded
     */
    public function recordAttempt(WebhookDelivery $delivery, array $attemptAttributes, array $deliveryChanges, bool $isSuccess, bool $isReceiverOutcome): bool
    {
        /** @var bool $isRecorded Laravel 12 types transaction() as mixed. */
        $isRecorded = WebhookDelivery::query()->getConnection()->transaction(function () use ($delivery, $attemptAttributes, $deliveryChanges, $isSuccess, $isReceiverOutcome): bool {
            $storedDelivery = WebhookDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();

            // Gone (deleted while it was sent) or recorded by another worker.
            if ($storedDelivery?->attempts_count !== $delivery->attempts_count) {
                return false;
            }

            $storedDelivery->attempts()->make()->forceFill($attemptAttributes)->save();
            $storedDelivery->forceFill([
                ...$deliveryChanges,
                'attempts_count' => $delivery->attempts_count + 1,
                'claimed_until' => null,
            ])->save();
            if ($isReceiverOutcome) {
                $this->moveFailureStreak($delivery->endpoint_id, $isSuccess);
            }

            return true;
        });

        return $isRecorded;
    }

    /**
     * Switch an active endpoint off. Only one caller wins, so the disable is
     * reported once. An automatic disable also requires the streak it was
     * decided on to be there still, in the same statement: a success another
     * worker recorded meanwhile cancels it.
     *
     * @param  array{minConsecutiveFailures: int, failingSinceAtLatest: CarbonImmutable|null}|null  $streakStillRequired
     * @return bool whether this call disabled it
     */
    public function disableEndpoint(int $endpointId, WebhookEndpointDisabledReasonEnum $reason, CarbonImmutable $disabledAt, ?array $streakStillRequired = null): bool
    {
        $endpointQuery = WebhookEndpoint::withTrashed()->whereKey($endpointId)->where('is_active', true);

        if ($streakStillRequired !== null) {
            $endpointQuery->where('consecutive_failures', '>=', $streakStillRequired['minConsecutiveFailures']);

            if ($streakStillRequired['failingSinceAtLatest'] !== null) {
                $endpointQuery->where('failing_since', '<=', $streakStillRequired['failingSinceAtLatest']->utc());
            }
        }

        return $endpointQuery->update([
            'is_active' => false,
            'disabled_at' => $disabledAt->utc(),
            'disabled_reason' => $reason->value,
        ]) === 1;
    }

    /**
     * Switch a disabled endpoint back on with a clean failure streak, so the
     * next failure does not disable it again at once. Its pending deliveries
     * resume on their schedule.
     *
     * @return bool whether this call enabled it
     */
    public function enableEndpoint(int $endpointId): bool
    {
        return WebhookEndpoint::query()->whereKey($endpointId)->where('is_active', false)->update([
            'is_active' => true,
            'disabled_at' => null,
            'disabled_reason' => null,
            'consecutive_failures' => 0,
            'failing_since' => null,
        ]) === 1;
    }

    /**
     * Pending deliveries no worker holds.
     *
     * @param  Builder<WebhookDelivery>  $deliveriesQuery
     * @return Builder<WebhookDelivery>
     */
    private function claimable(Builder $deliveriesQuery, CarbonImmutable $now): Builder
    {
        return $deliveriesQuery
            ->where('status', DeliveryStatus::Pending->value)
            ->where(function (Builder $leaseQuery) use ($now): void {
                $leaseQuery->whereNull('claimed_until')->orWhere('claimed_until', '<=', $now);
            });
    }

    /**
     * The streak the auto-disable policy reads: a success clears it, a
     * failure extends it and keeps the time it started.
     */
    private function moveFailureStreak(int $endpointId, bool $isSuccess): void
    {
        $now = CarbonImmutable::now('UTC');
        $endpointQuery = WebhookEndpoint::withTrashed()->whereKey($endpointId);

        if ($isSuccess) {
            $endpointQuery->update(['consecutive_failures' => 0, 'failing_since' => null, 'last_success_at' => $now]);

            return;
        }

        $endpointQuery->clone()->increment('consecutive_failures', 1, ['last_failure_at' => $now]);
        $endpointQuery->whereNull('failing_since')->update(['failing_since' => $now]);
    }
}
