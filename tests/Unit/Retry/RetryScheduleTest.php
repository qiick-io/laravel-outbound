<?php

declare(strict_types=1);

use Qiick\Outbound\Retry\Randomizer;
use Qiick\Outbound\Retry\RetrySchedule;
use Qiick\Outbound\Retry\SystemRandomizer;
use Qiick\Outbound\Tests\Support\FixedRandomizer;

it('exposes the Standard Webhooks example delays', function (): void {
    expect(RetrySchedule::standardDelays())->toBe([
        5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400,
    ]);
});

it('exposes the short migration-window delays', function (): void {
    expect(RetrySchedule::shortDelays())->toBe([10, 30, 60]);
});

it('returns the base delay when jitter is zero', function (): void {
    $random = new FixedRandomizer([999]);

    expect(RetrySchedule::withJitter(300, 0, $random))->toBe(300);
});

it('allows a zero base delay', function (): void {
    expect(RetrySchedule::withJitter(0, 0, new FixedRandomizer([1])))->toBe(0);
});

it('adds a positive jitter offset within the delay/2 amplitude', function (): void {
    // amplitude = min(30, floor(100/2)) = 30
    $random = new FixedRandomizer([12]);

    expect(RetrySchedule::withJitter(100, 30, $random))->toBe(112);
});

it('adds a negative jitter offset within the delay/2 amplitude', function (): void {
    $random = new FixedRandomizer([-25]);

    expect(RetrySchedule::withJitter(100, 30, $random))->toBe(75);
});

it('never makes the first standard delay immediate when jitter exceeds delay/2', function (): void {
    // delay=5, configured jitter=30 → amplitude=2; worst case wait is 3, never 0
    $seen = new class implements Randomizer
    {
        public int $min = 0;

        public int $max = 0;

        public function intBetween(int $min, int $max): int
        {
            $this->min = $min;
            $this->max = $max;

            return $min;
        }
    };

    expect(RetrySchedule::withJitter(5, 30, $seen))->toBe(3)
        ->and($seen->min)->toBe(-2)
        ->and($seen->max)->toBe(2);
});

it('clamps a randomizer that returns outside ±amplitude', function (): void {
    $random = new class implements Randomizer
    {
        public function intBetween(int $min, int $max): int
        {
            return $min - 100; // deliberately below the requested range
        }
    };

    // delay=100, amplitude=30 → clamped offset -30 → 70
    expect(RetrySchedule::withJitter(100, 30, $random))->toBe(70);
});

it('caps delays at MAX_DELAY_SECONDS', function (): void {
    $max = RetrySchedule::MAX_DELAY_SECONDS;

    expect(RetrySchedule::withJitter($max + 100, 0, new FixedRandomizer([1])))->toBe($max)
        ->and(RetrySchedule::withJitter($max, 0, new FixedRandomizer([1])))->toBe($max);
});

it('caps configured jitter at MAX_JITTER_SECONDS before delay/2', function (): void {
    $seen = new class implements Randomizer
    {
        public int $max = 0;

        public function intBetween(int $min, int $max): int
        {
            $this->max = $max;

            return 0;
        }
    };

    // delay large enough that floor(delay/2) > MAX_JITTER; amplitude must be MAX_JITTER
    RetrySchedule::withJitter(100_000, RetrySchedule::MAX_JITTER_SECONDS + 999, $seen);

    expect($seen->max)->toBe(RetrySchedule::MAX_JITTER_SECONDS);
});

it('caps jittered results that would exceed MAX_DELAY_SECONDS', function (): void {
    $max = RetrySchedule::MAX_DELAY_SECONDS;
    // amplitude = min(10, floor(max/2)) = 10; offset +10 would exceed if delay were max
    // but delay is capped to max first, amplitude = min(10, max/2)=10, result max+10 capped
    $random = new FixedRandomizer([10]);

    expect(RetrySchedule::withJitter($max, 10, $random))->toBe($max);
});

it('refuses negative delay and jitter', function (int $delay, int $jitter, string $message): void {
    expect(fn () => RetrySchedule::withJitter($delay, $jitter, new FixedRandomizer([0])))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'negative delay' => [-1, 0, 'Retry delay must not be negative.'],
    'negative jitter' => [10, -1, 'Retry jitter must not be negative.'],
]);

it('asks the randomizer for the proportional amplitude range', function (): void {
    // delay=60, jitter=30 → amplitude=30; FixedRandomizer([-30]) fits once
    $random = new FixedRandomizer([-30]);

    RetrySchedule::withJitter(60, 30, $random);

    expect(fn () => RetrySchedule::withJitter(60, 30, $random))
        ->toThrow(RuntimeException::class);
});

it('lets SystemRandomizer draw within the requested bounds', function (): void {
    $value = (new SystemRandomizer)->intBetween(3, 3);

    expect($value)->toBe(3);
});

it('refuses SystemRandomizer when min exceeds max', function (): void {
    expect(fn () => (new SystemRandomizer)->intBetween(5, 1))
        ->toThrow(InvalidArgumentException::class);
});

it('pins the public caps', function (): void {
    expect(RetrySchedule::MAX_DELAY_SECONDS)->toBe(2_592_000)
        ->and(RetrySchedule::MAX_JITTER_SECONDS)->toBe(3_600);
});
