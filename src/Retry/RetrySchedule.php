<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

/**
 * Pure retry delay tables and jitter for outbound webhook delivery.
 *
 * Prefer {@see standardDelays()} (Standard Webhooks example). Bound
 * {@see shortDelays()} to a migration window only. Products own concurrency
 * caps, circuit-break / auto-disable, and honouring Retry-After / 410 / 429.
 *
 * @api
 */
final class RetrySchedule
{
    /**
     * Standard Webhooks example delays (seconds).
     *
     * @return list<int>
     */
    public static function standardDelays(): array
    {
        return [5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400];
    }

    /**
     * Short migration-window delays (seconds): [10, 30, 60].
     *
     * @return list<int>
     */
    public static function shortDelays(): array
    {
        return [10, 30, 60];
    }

    /**
     * Apply symmetric jitter: delay + random in [-jitter, +jitter], floored at 0.
     *
     * @throws \InvalidArgumentException when delay or jitter is negative
     */
    public static function withJitter(int $delaySeconds, int $jitterSeconds, Randomizer $random): int
    {
        if ($delaySeconds < 0) {
            throw new \InvalidArgumentException('Retry delay must not be negative.');
        }

        if ($jitterSeconds < 0) {
            throw new \InvalidArgumentException('Retry jitter must not be negative.');
        }

        if ($jitterSeconds === 0) {
            return $delaySeconds;
        }

        $offset = $random->intBetween(-$jitterSeconds, $jitterSeconds);
        $withJitter = $delaySeconds + $offset;

        // Floor at zero: `< 0` vs `<= 0` and ±1 on the literal are equivalent.
        return $withJitter < 0 ? 0 : $withJitter; // @pest-mutate-ignore: SmallerToSmallerOrEqual,DecrementInteger,IncrementInteger
    }
}
