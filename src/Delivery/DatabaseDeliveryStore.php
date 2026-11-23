<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
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
     * Lease up to `$limit` due deliveries, oldest first, within each
     * endpoint's concurrency. Each comes with its endpoint loaded.
     *
     * @return list<WebhookDelivery>
     */
    public function claimDue(int $limit, int $leaseSeconds, int $defaultMaxConcurrency): array
    {
        $now = CarbonImmutable::now('UTC');

        $dueDeliveries = $this->claimable(WebhookDelivery::query(), $now)
            ->where('next_attempt_at', '<=', $now)
            ->whereHas('endpoint', function (Builder $endpointQuery): void {
                $endpointQuery->where('is_active', true)->whereNull('deleted_at');
            })
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $claimedDeliveries = [];

        foreach ($dueDeliveries->groupBy('endpoint_id') as $endpointDeliveries) {
            array_push($claimedDeliveries, ...$this->claimForEndpoint($endpointDeliveries, $now, $leaseSeconds, $defaultMaxConcurrency));
        }

        return $claimedDeliveries;
    }

    /**
     * Record one attempt and its outcome, and move the endpoint's failure
     * streak, in one transaction. Nothing is written when another worker has
     * already recorded this attempt (its lease had expired and the delivery
     * was taken again): the stored attempt count no longer matches.
     *
     * @param  array<string, mixed>  $attemptAttributes
     * @param  array<string, mixed>  $deliveryChanges
     */
    public function recordAttempt(WebhookDelivery $delivery, array $attemptAttributes, array $deliveryChanges, bool $isSuccess): void
    {
        WebhookDelivery::query()->getConnection()->transaction(function () use ($delivery, $attemptAttributes, $deliveryChanges, $isSuccess): void {
            $storedDelivery = WebhookDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();

            // Gone (deleted while it was sent) or recorded by another worker.
            if ($storedDelivery?->attempts_count !== $delivery->attempts_count) {
                return;
            }

            $storedDelivery->attempts()->make()->forceFill($attemptAttributes)->save();
            $storedDelivery->forceFill([
                ...$deliveryChanges,
                'attempts_count' => $delivery->attempts_count + 1,
                'claimed_until' => null,
            ])->save();
            $this->moveFailureStreak($delivery->endpoint_id, $isSuccess);
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, WebhookDelivery>  $endpointDeliveries  due, oldest first
     * @return list<WebhookDelivery>
     */
    private function claimForEndpoint(\Illuminate\Support\Collection $endpointDeliveries, CarbonImmutable $now, int $leaseSeconds, int $defaultMaxConcurrency): array
    {
        $endpointId = $endpointDeliveries->firstOrFail()->endpoint_id;

        /** @var list<WebhookDelivery> $claimedDeliveries Laravel 12 types transaction() as mixed. */
        $claimedDeliveries = WebhookEndpoint::query()->getConnection()->transaction(function () use ($endpointId, $endpointDeliveries, $now, $leaseSeconds, $defaultMaxConcurrency): array {
            $endpoint = WebhookEndpoint::query()->whereKey($endpointId)->lockForUpdate()->firstOrFail();
            $leasedCount = WebhookDelivery::query()
                ->where('endpoint_id', $endpointId)
                ->where('status', DeliveryStatus::Pending->value)
                ->where('claimed_until', '>', $now)
                ->count();
            $freeSlots = ($endpoint->max_concurrency ?? $defaultMaxConcurrency) - $leasedCount;
            $leasedUntil = $now->addSeconds($leaseSeconds);
            $claimedDeliveries = [];

            // take() counts from the end for a negative number: no free slot is zero.
            foreach ($endpointDeliveries->take(max(0, $freeSlots)) as $delivery) {
                // Another worker may have leased it since it was read.
                $leasedRows = $this->claimable(WebhookDelivery::query()->whereKey($delivery->id), $now)
                    ->update(['claimed_until' => $leasedUntil]);

                if ($leasedRows === 1) {
                    $claimedDeliveries[] = $delivery->setRelation('endpoint', $endpoint);
                }
            }

            return $claimedDeliveries;
        });

        return $claimedDeliveries;
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
