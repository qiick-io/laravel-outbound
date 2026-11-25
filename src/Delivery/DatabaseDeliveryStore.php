<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookSubscription;
use Qiick\Outbound\Retry\RetryPolicy;
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
     * @param  int  $payloadRetentionDays  `outbound.webhooks.payload_retention_days`
     * @param  int  $deliveryRetentionDays  `outbound.webhooks.delivery_retention_days`
     *
     * @throws InvalidArgumentException when the body retention is under a day
     *                                  or the rows would outlast it less
     */
    public function __construct(
        private readonly ?string $scopeColumn,
        private readonly int $payloadRetentionDays,
        private readonly int $deliveryRetentionDays,
    ) {
        if ($payloadRetentionDays < 1) {
            throw new InvalidArgumentException('outbound.webhooks.payload_retention_days must be at least 1.');
        }

        // A row deleted earlier would take its body with it before its time.
        if ($deliveryRetentionDays < $payloadRetentionDays) {
            throw new InvalidArgumentException('outbound.webhooks.delivery_retention_days must be at least outbound.webhooks.payload_retention_days.');
        }
    }

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
     * A pending delivery, due now. Its body is kept for the retention period,
     * or until its longest possible retry plan ends, plus an hour for the
     * worker's own schedule, if that is later: a body must never expire
     * mid-schedule.
     *
     * @return WebhookDelivery|null null when the endpoint already has this event
     *
     * @throws WebhookDeliveryStorageException when the row cannot be written
     */
    public function createDelivery(WebhookEndpoint $endpoint, WebhookMessage $message, string $rawBody, int $maxAttempts, int $retryPlanSeconds): ?WebhookDelivery
    {
        $delivery = $endpoint->deliveries()->make([
            'event_id' => $message->id,
            'event_type' => $message->type,
            'occurred_at' => $message->timestamp,
            'payload_version' => $endpoint->payload_version,
            'max_attempts' => $maxAttempts,
        ])->usePayload($rawBody)->forceFill([
            'next_attempt_at' => CarbonImmutable::now(),
            'payload_expires_at' => CarbonImmutable::now()->addSeconds(max($this->payloadRetentionDays * 86_400, $retryPlanSeconds + 3_600)),
        ]);

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
            // An endpoint at its rate limit would only take slots it cannot use.
            ->where(function (Builder $windowQuery) use ($now): void {
                $windowQuery->whereNull('webhook_endpoints.rate_limit_per_minute')
                    ->orWhere('webhook_endpoints.rate_limit_per_minute', '<', 1)
                    ->orWhereNull('webhook_endpoints.rate_window_started_at')
                    ->orWhere('webhook_endpoints.rate_window_started_at', '<=', $now->subMinute())
                    ->orWhereColumn('webhook_endpoints.rate_window_attempts', '<', 'webhook_endpoints.rate_limit_per_minute');
            })
            ->groupBy('webhook_deliveries.endpoint_id')
            ->select('webhook_deliveries.endpoint_id')
            ->selectRaw('min(next_attempt_at) as oldest_due_at')
            ->orderBy('oldest_due_at')
            ->orderBy('webhook_deliveries.endpoint_id')
            ->limit($limit)
            ->toBase()
            ->get();

        /** @var \Illuminate\Support\Collection<int, WebhookDelivery> $candidateDeliveries */
        $candidateDeliveries = new \Illuminate\Support\Collection;

        // As many of each endpoint as claim() would lease now.
        foreach (WebhookEndpoint::query()->findMany($endpointsWithDueDeliveries->pluck('endpoint_id')) as $endpoint) {
            $candidateDeliveries->push(...$this->claimable(WebhookDelivery::query(), $now)
                ->where('endpoint_id', $endpoint->id)
                ->where('next_attempt_at', '<=', $now)
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->limit(min(self::concurrencyOf($endpoint, $defaultMaxConcurrency), self::attemptsLeftInWindow($endpoint, $now) ?? PHP_INT_MAX))
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

            $attemptsLeft = self::attemptsLeftInWindow($endpoint, $now);

            if ($leasedCount >= self::concurrencyOf($endpoint, $defaultMaxConcurrency) || $attemptsLeft === 0) {
                return null;
            }

            $isNewWindow = self::isNewWindow($endpoint, $now);

            $leasedRows = $this->claimable(WebhookDelivery::query()->whereKey($deliveryId), $now)
                ->where('next_attempt_at', '<=', $now)
                ->update(['claimed_until' => $now->addSeconds($leaseSeconds)]);

            if ($leasedRows !== 1) {
                return null;
            }

            // Only a limited endpoint counts its window.
            if ($attemptsLeft !== null) {
                WebhookEndpoint::withTrashed()->whereKey($endpoint->id)->update([
                    'rate_window_started_at' => $isNewWindow ? $now : $endpoint->rate_window_started_at,
                    'rate_window_attempts' => ($isNewWindow ? 0 : $endpoint->rate_window_attempts) + 1,
                ]);
            }

            return WebhookDelivery::query()->findOrFail($deliveryId)->setRelation('endpoint', $endpoint);
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

            // A redelivery asked for while this attempt was on the wire: the
            // worker claimed the delivery without it, so recording must not
            // clear it. The request count, not the trigger or the user, tells
            // it apart: the same user can ask twice.
            $redeliveryAskedMeanwhile = $storedDelivery->redelivery_requests !== $delivery->redelivery_requests;
            $askedTrigger = $storedDelivery->next_attempt_trigger;
            $askedBy = $storedDelivery->next_attempt_triggered_by;

            $storedDelivery->attempts()->make()->forceFill($attemptAttributes)->save();
            $storedDelivery->forceFill([
                ...$deliveryChanges,
                'attempts_count' => $delivery->attempts_count + 1,
                'claimed_until' => null,
            ])->save();
            if ($isReceiverOutcome) {
                $httpStatus = $attemptAttributes['http_status'] ?? null;
                $this->moveFailureStreak($delivery->endpoint_id, $isSuccess, is_int($httpStatus) ? $httpStatus : null);
            }

            if ($redeliveryAskedMeanwhile && $askedTrigger !== null) {
                $this->scheduleRedelivery(WebhookDelivery::query()->whereKey($delivery->id), $askedTrigger, $askedBy);
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
     * Schedule the deliveries the query selects for an attempt now, marked
     * with the trigger and the user the worker records on it. A settled
     * delivery (delivered, exhausted, refused, cancelled) is pending again
     * with exactly one more attempt: if it fails, the delivery is exhausted
     * again rather than starting a new schedule. A pending one keeps its
     * schedule; only its next attempt comes sooner.
     *
     * @param  Builder<WebhookDelivery>  $deliveriesQuery
     * @return int the deliveries scheduled
     */
    public function scheduleRedelivery(Builder $deliveriesQuery, WebhookAttemptTriggerEnum $trigger, ?int $triggeredBy): int
    {
        $redeliveryRequest = [
            'next_attempt_at' => CarbonImmutable::now('UTC'),
            'next_attempt_trigger' => $trigger->value,
            'next_attempt_triggered_by' => $triggeredBy,
            'redelivery_requests' => WebhookDelivery::query()->getQuery()->raw('redelivery_requests + 1'),
        ];

        /** @var int $scheduledCount Laravel 12 types transaction() as mixed. */
        $scheduledCount = WebhookDelivery::query()->getConnection()->transaction(function () use ($deliveriesQuery, $redeliveryRequest): int {
            $pendingCount = $deliveriesQuery->clone()
                ->where('status', DeliveryStatus::Pending->value)
                ->update($redeliveryRequest);
            $settledCount = $deliveriesQuery->clone()
                ->where('status', '!=', DeliveryStatus::Pending->value)
                ->update([
                    ...$redeliveryRequest,
                    'status' => DeliveryStatus::Pending->value,
                    'exhausted_reason' => null,
                    'max_attempts' => WebhookDelivery::query()->getQuery()->raw('attempts_count + 1'),
                ]);

            return $pendingCount + $settledCount;
        });

        return $scheduledCount;
    }

    /**
     * Clear every body whose retention has ended, keeping `payload_hash` and
     * `payload_bytes` (standards review R5). A delivery a worker is sending
     * keeps its body until its lease ends. A pending delivery can no longer
     * be signed without its body, so it is exhausted (`payload_expired`).
     * Works in blocks of 500, each its own short transaction, so a large
     * backlog never holds the table the worker writes to.
     *
     * @return int the bodies cleared
     */
    public function pruneExpiredPayloads(): int
    {
        $now = CarbonImmutable::now('UTC');
        $prunedCount = 0;

        do {
            $expiredBlock = WebhookDelivery::query()
                ->whereNotNull('payload')
                ->where('payload_expires_at', '<=', $now)
                ->where(function (Builder $leaseQuery) use ($now): void {
                    $leaseQuery->whereNull('claimed_until')->orWhere('claimed_until', '<=', $now);
                })
                ->orderBy('id')
                ->limit(500)
                ->get(['id', 'status']);
            $pendingIds = $expiredBlock->filter(static fn (WebhookDelivery $delivery): bool => $delivery->status === DeliveryStatus::Pending)->modelKeys();
            $settledIds = $expiredBlock->reject(static fn (WebhookDelivery $delivery): bool => $delivery->status === DeliveryStatus::Pending)->modelKeys();

            WebhookDelivery::query()->getConnection()->transaction(function () use ($pendingIds, $settledIds): void {
                if ($pendingIds !== []) {
                    WebhookDelivery::query()->whereKey($pendingIds)->update([
                        'payload' => null,
                        'status' => DeliveryStatus::Exhausted->value,
                        'exhausted_reason' => WebhookExhaustedReasonEnum::PayloadExpired->value,
                        'next_attempt_at' => null,
                        'next_attempt_trigger' => null,
                        'next_attempt_triggered_by' => null,
                    ]);
                }

                if ($settledIds !== []) {
                    WebhookDelivery::query()->whereKey($settledIds)->update(['payload' => null]);
                }
            });
            $prunedCount += $expiredBlock->count();
        } while ($expiredBlock->count() === 500);

        return $prunedCount;
    }

    /**
     * Delete settled deliveries created `delivery_retention_days` ago or
     * more, with their attempts (cascade). Pending ones are kept: they still
     * have attempts to make. So are rows whose body has not expired: a long
     * retry plan can keep a body past `delivery_retention_days`, and until
     * then it can still be redelivered. Works in blocks of 500.
     *
     * @return int the deliveries deleted
     */
    public function deleteExpiredDeliveries(): int
    {
        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subDays($this->deliveryRetentionDays);
        $deletedCount = 0;

        do {
            $expiredIds = WebhookDelivery::query()
                ->where('status', '!=', DeliveryStatus::Pending->value)
                ->where('created_at', '<=', $cutoff)
                ->where('payload_expires_at', '<=', $now)
                ->orderBy('id')
                ->limit(500)
                ->pluck('id')
                ->all();
            WebhookDelivery::query()->whereKey($expiredIds)->delete();
            $deletedCount += count($expiredIds);
        } while (count($expiredIds) === 500);

        return $deletedCount;
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
     * The endpoint's concurrency, halved (at least 1) while an overload
     * answer throttles it, until its next success (standards review S7).
     */
    private static function concurrencyOf(WebhookEndpoint $endpoint, int $defaultMaxConcurrency): int
    {
        $maxConcurrency = $endpoint->max_concurrency ?? $defaultMaxConcurrency;

        return $endpoint->throttled_at === null ? $maxConcurrency : max(1, intdiv($maxConcurrency, 2));
    }

    /**
     * The attempts the endpoint may still start in its current one-minute
     * window; null without a limit (`rate_limit_per_minute` null or 0).
     */
    private static function attemptsLeftInWindow(WebhookEndpoint $endpoint, CarbonImmutable $now): ?int
    {
        $limit = $endpoint->rate_limit_per_minute;

        if (! ($limit > 0)) {
            return null;
        }

        return max(0, $limit - (self::isNewWindow($endpoint, $now) ? 0 : $endpoint->rate_window_attempts));
    }

    /**
     * A window begins with the first attempt and lasts a minute.
     */
    private static function isNewWindow(WebhookEndpoint $endpoint, CarbonImmutable $now): bool
    {
        return $endpoint->rate_window_started_at === null || $endpoint->rate_window_started_at->lessThanOrEqualTo($now->subMinute());
    }

    /**
     * The streak the auto-disable policy reads: a success clears it, a
     * failure extends it and keeps the time it started. An overload status
     * also throttles the endpoint, until a success lifts it.
     */
    private function moveFailureStreak(int $endpointId, bool $isSuccess, ?int $httpStatus): void
    {
        $now = CarbonImmutable::now('UTC');
        $endpointQuery = WebhookEndpoint::withTrashed()->whereKey($endpointId);

        if ($isSuccess) {
            $endpointQuery->update(['consecutive_failures' => 0, 'failing_since' => null, 'last_success_at' => $now, 'throttled_at' => null]);

            return;
        }

        $endpointQuery->clone()->increment('consecutive_failures', 1, [
            'last_failure_at' => $now,
            ...(RetryPolicy::isOverloadStatus($httpStatus) ? ['throttled_at' => $now] : []),
        ]);
        $endpointQuery->whereNull('failing_since')->update(['failing_since' => $now]);
    }
}
