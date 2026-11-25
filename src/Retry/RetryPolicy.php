<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

use InvalidArgumentException;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;

/**
 * Decides when a failed webhook attempt is retried.
 *
 * Standard Webhooks "Delivery success and failure": every non-2xx answer,
 * timeout and connection error is a failure retried on the schedule (a 3xx
 * is never followed), and 410 Gone stops delivery. 429, 502, 503 and 504 also
 * honour `Retry-After` (standards review R9). The order is fixed (review R4):
 *
 * 1. the schedule delay gets its jitter first;
 * 2. the `Retry-After` floor then wins, plus up to min(jitter, floor / 2)
 *    seconds so retries do not bunch up exactly at the floor: jitter never
 *    takes a retry below the floor;
 * 3. a floor above the cap is not shortened to the cap: automatic retries stop.
 *
 * @api
 */
final class RetryPolicy
{
    public function __construct(
        private readonly Randomizer $random,
        private readonly int $jitterSeconds = 30,
        private readonly bool $respectRetryAfter = true,
        private readonly int $retryAfterCapSeconds = 86_400,
    ) {}

    /**
     * @param  int  $attemptNumber  the 1-based attempt that just failed
     * @param  int  $maxAttempts  the delivery's snapshot, first attempt included
     * @param  list<int>  $delays  the wait before each retry; the last repeats
     *                             when `$maxAttempts` outlasts the schedule
     * @param  int|null  $httpStatus  null for a transport error or a refusal
     * @param  int|null  $retryAfterSeconds  from {@see RetryAfter::parse()}
     *
     * @throws InvalidArgumentException when the schedule is empty
     */
    public function decide(int $attemptNumber, int $maxAttempts, array $delays, ?int $httpStatus, ?int $retryAfterSeconds): RetryDecision
    {
        if ($delays === []) {
            throw new InvalidArgumentException('A retry schedule needs at least one delay.');
        }

        if ($httpStatus === 410) {
            return RetryDecision::exhausted(WebhookExhaustedReasonEnum::Gone);
        }

        if ($attemptNumber >= $maxAttempts) {
            return RetryDecision::exhausted(WebhookExhaustedReasonEnum::MaxAttempts);
        }

        $floorSeconds = $this->floorSeconds($httpStatus, $retryAfterSeconds);

        if ($floorSeconds > $this->retryAfterCapSeconds) {
            return RetryDecision::exhausted(WebhookExhaustedReasonEnum::RetryAfterExceedsCap);
        }

        $scheduleDelay = $delays[min($attemptNumber, count($delays)) - 1];
        $scheduledSeconds = RetrySchedule::withJitter($scheduleDelay, $this->jitterSeconds, $this->random);

        if ($floorSeconds === 0) {
            return RetryDecision::retryIn($scheduledSeconds);
        }

        $jitterAboveFloor = $this->random->intBetween(0, min($this->jitterSeconds, intdiv($floorSeconds, 2)));

        return RetryDecision::retryIn(max($scheduledSeconds, $floorSeconds + $jitterAboveFloor));
    }

    /**
     * The longest a delivery's automatic retries can take: every retry at its
     * schedule delay plus the most jitter, or, when Retry-After is honoured,
     * at the cap plus its jitter if that is later. A body must be kept at
     * least this long, or a delivery would lose it mid-schedule.
     *
     * @param  list<int>  $delays  the schedule; the last repeats
     * @param  int  $maxAttempts  the first attempt included
     */
    public function longestPlanSeconds(array $delays, int $maxAttempts): int
    {
        $planSeconds = 0;

        for ($attemptNumber = 1; $attemptNumber < $maxAttempts; $attemptNumber++) {
            $scheduleDelay = $delays[min($attemptNumber, count($delays)) - 1];
            $latestScheduled = $scheduleDelay + min($this->jitterSeconds, intdiv($scheduleDelay, 2));
            $latestFloor = $this->respectRetryAfter
                ? $this->retryAfterCapSeconds + min($this->jitterSeconds, intdiv($this->retryAfterCapSeconds, 2))
                : 0;
            $planSeconds += max($latestScheduled, $latestFloor);
        }

        return $planSeconds;
    }

    /**
     * 429, 502, 503 and 504: the receiver is overloaded, so its Retry-After
     * is honoured and its concurrency halved (standards review R4, S7).
     */
    public static function isOverloadStatus(?int $httpStatus): bool
    {
        return in_array($httpStatus, [429, 502, 503, 504], true);
    }

    private function floorSeconds(?int $httpStatus, ?int $retryAfterSeconds): int
    {
        if (! $this->respectRetryAfter || $retryAfterSeconds === null) {
            return 0;
        }

        return self::isOverloadStatus($httpStatus) ? $retryAfterSeconds : 0;
    }
}
