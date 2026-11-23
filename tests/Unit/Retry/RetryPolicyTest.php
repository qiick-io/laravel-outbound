<?php

declare(strict_types=1);

use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Enums\WebhookRetryPresetEnum;
use Qiick\Outbound\Retry\Randomizer;
use Qiick\Outbound\Retry\RetryDecision;
use Qiick\Outbound\Retry\RetryPolicy;
use Qiick\Outbound\Retry\RetrySchedule;
use Qiick\Outbound\Tests\Support\FixedRandomizer;

/*
 * Standards review R4 (jitter first, the Retry-After floor never undercut, a
 * floor above the cap stops automatic retries) and R9 (every non-2xx is a
 * failure; 410 stops; 429/502/503/504 honour Retry-After).
 */

/**
 * @param  list<int>  $randomValues
 */
function retryPolicy(array $randomValues = [], int $jitterSeconds = 30, bool $respectRetryAfter = true, int $retryAfterCapSeconds = 86_400): RetryPolicy
{
    return new RetryPolicy(new FixedRandomizer($randomValues), $jitterSeconds, $respectRetryAfter, $retryAfterCapSeconds);
}

it('follows the Standard Webhooks schedule: ten attempts over 75 h 35 min', function (): void {
    $delays = WebhookRetryPresetEnum::Standard->delays();
    $totalSeconds = 0;

    foreach (range(1, 9) as $attemptNumber) {
        $decision = retryPolicy(jitterSeconds: 0)->decide($attemptNumber, 10, $delays, 500, null);
        $totalSeconds += (int) $decision->delaySeconds;
    }

    expect($delays)->toBe(RetrySchedule::standardDelays())
        ->and(WebhookRetryPresetEnum::Short->delays())->toBe(RetrySchedule::shortDelays())
        ->and($totalSeconds)->toBe(75 * 3600 + 35 * 60 + 5)
        ->and(retryPolicy()->decide(10, 10, $delays, 500, null))->toEqual(RetryDecision::exhausted(WebhookExhaustedReasonEnum::MaxAttempts));
});

it('uses the delay of the attempt that just failed, with jitter', function (): void {
    $decision = retryPolicy([-7])->decide(2, 10, [5, 300, 1800], 500, null);

    expect($decision->delaySeconds)->toBe(293)
        ->and($decision->exhaustedReason)->toBeNull()
        ->and($decision->isExhausted())->toBeFalse();
});

it('keeps the last delay when max attempts outlast the schedule', function (): void {
    expect(retryPolicy(jitterSeconds: 0)->decide(5, 20, [10, 30, 60], 500, null)->delaySeconds)->toBe(60)
        ->and(retryPolicy(jitterSeconds: 0)->decide(3, 20, [10, 30, 60], 500, null)->delaySeconds)->toBe(60);
});

it('stops at max attempts, and before it on a lower snapshot', function (): void {
    expect(retryPolicy()->decide(3, 3, [5, 300, 1800], 500, null)->exhaustedReason)->toBe(WebhookExhaustedReasonEnum::MaxAttempts)
        ->and(retryPolicy()->decide(4, 3, [5, 300, 1800], 500, null)->isExhausted())->toBeTrue()
        ->and(retryPolicy(jitterSeconds: 0)->decide(2, 3, [5, 300, 1800], 500, null)->delaySeconds)->toBe(300);
});

it('stops at once on 410 Gone', function (): void {
    $decision = retryPolicy()->decide(1, 10, [5], 410, 60);

    expect($decision)->toEqual(RetryDecision::exhausted(WebhookExhaustedReasonEnum::Gone))
        ->and($decision->delaySeconds)->toBeNull()
        ->and($decision->isExhausted())->toBeTrue();
});

it('retries every other failure, 3xx, 4xx and transport errors included', function (?int $httpStatus): void {
    expect(retryPolicy(jitterSeconds: 0)->decide(1, 10, [5], $httpStatus, null)->delaySeconds)->toBe(5);
})->with([301, 302, 400, 401, 404, 409, 422, 500, 501, null]);

it('waits at least Retry-After on 429, 502, 503 and 504, with jitter only above the floor', function (int $httpStatus): void {
    // Schedule 5 s with jitter -2 (amplitude 2) = 3 s; floor 120 s plus 17 s.
    $decision = retryPolicy([-2, 17])->decide(1, 10, [5], $httpStatus, 120);

    expect($decision->delaySeconds)->toBe(137);
})->with([429, 502, 503, 504]);

it('never goes below the floor, even with the most negative jitter on the schedule', function (): void {
    // Schedule 300 s jitter -30 = 270 s; floor 280 s plus 0 = 280 s.
    expect(retryPolicy([-30, 0])->decide(2, 10, [5, 300], 503, 280)->delaySeconds)->toBe(280);
});

it('keeps the schedule when it is already later than the floor', function (): void {
    // Schedule 1800 s jitter +30 = 1830 s; floor 60 s plus 30 = 90 s.
    expect(retryPolicy([30, 30])->decide(3, 10, [5, 300, 1800], 503, 60)->delaySeconds)->toBe(1830);
});

it('bounds the jitter above the floor by half the floor and by the configured jitter', function (): void {
    // Floor 10 s: jitter at most min(30, 5) = 5; a FixedRandomizer value of 6 would throw.
    expect(retryPolicy([0, 5])->decide(1, 10, [5], 503, 10)->delaySeconds)->toBe(15)
        ->and(fn () => retryPolicy([0, 6])->decide(1, 10, [5], 503, 10))->toThrow(RuntimeException::class, 'outside [0, 5]')
        // Floor 600 s: at most min(30, 300) = 30.
        ->and(retryPolicy([0, 30])->decide(1, 10, [5], 503, 600)->delaySeconds)->toBe(630)
        ->and(fn () => retryPolicy([0, 31])->decide(1, 10, [5], 503, 600))->toThrow(RuntimeException::class, 'outside [0, 30]');
});

it('draws no extra jitter for a zero floor', function (): void {
    expect(retryPolicy([1])->decide(1, 10, [5], 503, 0)->delaySeconds)->toBe(6);
});

it('ignores Retry-After on other statuses and when turned off', function (): void {
    expect(retryPolicy(jitterSeconds: 0)->decide(1, 10, [5], 500, 120)->delaySeconds)->toBe(5)
        ->and(retryPolicy(jitterSeconds: 0)->decide(1, 10, [5], 501, 120)->delaySeconds)->toBe(5)
        ->and(retryPolicy(jitterSeconds: 0)->decide(1, 10, [5], 200, 120)->delaySeconds)->toBe(5)
        ->and(retryPolicy(jitterSeconds: 0)->decide(1, 10, [5], null, 120)->delaySeconds)->toBe(5)
        ->and(retryPolicy(jitterSeconds: 0, respectRetryAfter: false)->decide(1, 10, [5], 503, 120)->delaySeconds)->toBe(5);
});

it('stops automatic retries when Retry-After exceeds the cap, without shortening it', function (): void {
    expect(retryPolicy(retryAfterCapSeconds: 3600)->decide(1, 10, [5], 503, 3601))
        ->toEqual(RetryDecision::exhausted(WebhookExhaustedReasonEnum::RetryAfterExceedsCap))
        ->and(retryPolicy([0, 0], jitterSeconds: 0, retryAfterCapSeconds: 3600)->decide(1, 10, [5], 503, 3600)->delaySeconds)->toBe(3600);
});

it('refuses an empty schedule', function (): void {
    retryPolicy()->decide(1, 10, [], 500, null);
})->throws(InvalidArgumentException::class, 'A retry schedule needs at least one delay.');

it('defaults to 30 s jitter, honours Retry-After and caps it at 24 h', function (): void {
    $recordingRandom = new class implements Randomizer
    {
        /** @var list<array{int, int}> */
        public array $ranges = [];

        public function intBetween(int $min, int $max): int
        {
            $this->ranges[] = [$min, $max];

            return $min;
        }
    };
    $policy = new RetryPolicy($recordingRandom);

    expect($policy->decide(2, 10, [5, 300], 503, 120)->delaySeconds)->toBe(270)
        ->and($recordingRandom->ranges)->toBe([[-30, 30], [0, 30]])
        ->and($policy->decide(1, 10, [5], 503, 86_401)->exhaustedReason)->toBe(WebhookExhaustedReasonEnum::RetryAfterExceedsCap)
        ->and($policy->decide(1, 10, [5], 503, 86_400)->isExhausted())->toBeFalse();
});

it('draws no jitter above the floor when a 503 has no Retry-After', function (): void {
    expect(retryPolicy([1])->decide(1, 10, [5], 503, null)->delaySeconds)->toBe(6);
});
