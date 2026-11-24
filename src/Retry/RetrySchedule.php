<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

/**
 * Pure retry delay tables and jitter for outbound webhook delivery.
 *
 * Prefer {@see standardDelays()} (Standard Webhooks example: 5s, 5m, 30m, 2h,
 * 5h, 10h, 14h, 20h, 24h — about 75.6 hours end-to-end). Each delay is the wait
 * from the previous attempt, not from the first send. Bound
 * {@see shortDelays()} to a migration window only. {@see RetryPolicy} applies
 * these delays with Retry-After and 410; the delivery coordinator enforces
 * per-endpoint concurrency.
 *
 * @api
 */
final class RetrySchedule
{
    /**
     * Hard cap so delay + jitter never overflows PHP integers (30 days).
     * Methods rather than constants: a constant's line has no coverage, so
     * mutation testing could not check the values.
     */
    public static function maxDelaySeconds(): int
    {
        return 2_592_000;
    }

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
     * Apply symmetric jitter capped to ±min(jitter, floor(delay/2)) so a
     * positive delay never becomes an immediate retry. The randomizer offset
     * is clamped to that amplitude; the result is capped at {@see maxDelaySeconds()}.
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

        $delaySeconds = min($delaySeconds, self::maxDelaySeconds());
        $jitterSeconds = min($jitterSeconds, self::maxJitterSeconds());

        // Amplitude ≤ floor(delay/2) keeps wait ≥ ceil(delay/2) for delay > 0.
        $amplitude = min($jitterSeconds, intdiv($delaySeconds, 2));
        if ($amplitude === 0) {
            return $delaySeconds;
        }

        // A randomizer that strays outside ±amplitude is clamped back.
        $offset = max(-$amplitude, min($amplitude, $random->intBetween(-$amplitude, $amplitude)));

        return min($delaySeconds + $offset, self::maxDelaySeconds());
    }

    /**
     * Cap on the configured jitter argument, before ±floor(delay/2) (1 hour).
     */
    private static function maxJitterSeconds(): int
    {
        return 3_600;
    }
}
