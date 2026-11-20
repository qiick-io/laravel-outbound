<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

/**
 * Pure retry delay tables and jitter for outbound webhook delivery.
 *
 * Prefer {@see standardDelays()} (Standard Webhooks example: 5s, 5m, 30m, 2h,
 * 5h, 10h, 14h, 20h, 24h — about 75.6 hours end-to-end). Bound
 * {@see shortDelays()} to a migration window only. Products own concurrency
 * caps, circuit-break / auto-disable, and honouring Retry-After / 410 / 429.
 *
 * @api
 */
final class RetrySchedule
{
    /**
     * Hard cap so delay + jitter never overflows PHP integers.
     */
    public const int MAX_DELAY_SECONDS = 2_592_000; // 30 days

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
     * is clamped to that amplitude; the result is capped at {@see MAX_DELAY_SECONDS}.
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

        $delaySeconds = min($delaySeconds, self::MAX_DELAY_SECONDS);

        // Amplitude ≤ floor(delay/2) keeps wait ≥ ceil(delay/2) for delay > 0.
        $amplitude = min($jitterSeconds, intdiv($delaySeconds, 2));
        if ($amplitude === 0) {
            return $delaySeconds;
        }

        $offset = $random->intBetween(-$amplitude, $amplitude);
        // Boundary == amplitude is a no-op clamp (same value either way).
        if ($offset < -$amplitude) { // @pest-mutate-ignore: SmallerToSmallerOrEqual
            $offset = -$amplitude;
        } elseif ($offset > $amplitude) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
            $offset = $amplitude;
        }

        $withJitter = $delaySeconds + $offset;
        // Equality with the cap returns the same value either branch.
        if ($withJitter > self::MAX_DELAY_SECONDS) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
            return self::MAX_DELAY_SECONDS;
        }

        return $withJitter;
    }
}
