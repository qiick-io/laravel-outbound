<?php

declare(strict_types=1);

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

it('adds a positive jitter offset from the randomizer', function (): void {
    $random = new FixedRandomizer([12]);

    expect(RetrySchedule::withJitter(100, 30, $random))->toBe(112);
});

it('adds a negative jitter offset from the randomizer', function (): void {
    $random = new FixedRandomizer([-25]);

    expect(RetrySchedule::withJitter(100, 30, $random))->toBe(75);
});

it('floors jittered delays at zero', function (): void {
    $random = new FixedRandomizer([-30]);

    expect(RetrySchedule::withJitter(5, 30, $random))->toBe(0);
});

it('refuses negative delay and jitter', function (int $delay, int $jitter, string $message): void {
    expect(fn () => RetrySchedule::withJitter($delay, $jitter, new FixedRandomizer([0])))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'negative delay' => [-1, 0, 'Retry delay must not be negative.'],
    'negative jitter' => [10, -1, 'Retry jitter must not be negative.'],
]);

it('asks the randomizer for the full symmetric jitter range', function (): void {
    $random = new FixedRandomizer([-30]);

    RetrySchedule::withJitter(60, 30, $random);

    // Consumed the only value that fits [-30, 30]; empty sequence would throw next.
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
