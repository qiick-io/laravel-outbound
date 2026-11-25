<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Tests\Support\RecordingTransferHandler;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookMessage;

/*
 * Standards review S7 (in 1.0 by the maintainer's decision D1): an endpoint's
 * rate_limit_per_minute caps the attempts sent to it per minute, and a 429 or
 * an overloaded 5xx halves its concurrency until its next success.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    config()->set('outbound.webhooks.retry_jitter_seconds', 0);
    $this->transfer = new RecordingTransferHandler;
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['hooks.example.com' => ['93.184.216.34']])),
        $this->app->make(OutboundHttpLimits::class),
        static fn (): float => 1_000.0,
        $this->transfer,
    ));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function throttledEndpoint(array $attributes): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive', ...$attributes]);
    $endpoint->save();
    $endpoint->subscriptions()->create(['event_type' => 'invoice.paid']);
    app(SecretRotator::class)->issue($endpoint);

    return $endpoint;
}

function dispatchInvoices(int $count): void
{
    foreach (range(1, $count) as $number) {
        app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage("msg_{$number}", 'invoice.paid', new DateTimeImmutable('2026-11-23 09:59:00'), ['n' => $number]));
    }
}

/**
 * @return int how many of the endpoint's due deliveries one more worker could lease now
 */
function leasesGranted(WebhookEndpoint $endpoint): int
{
    $granted = 0;

    foreach (WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->pluck('id') as $deliveryId) {
        $granted += app(DatabaseDeliveryStore::class)->claim($deliveryId, 600, 2) === null ? 0 : 1;
    }

    return $granted;
}

it('sends no more attempts to an endpoint than its rate limit per minute', function (): void {
    throttledEndpoint(['rate_limit_per_minute' => 2, 'max_concurrency' => 10]);
    dispatchInvoices(3);

    expect(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(2);

    CarbonImmutable::setTestNow('2026-11-23 10:00:59');

    expect(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(0);

    CarbonImmutable::setTestNow('2026-11-23 10:01:00');

    expect(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(1)
        ->and($this->transfer->requests)->toHaveCount(3);
});

it('sends without a per-minute limit when the endpoint has none', function (): void {
    throttledEndpoint(['max_concurrency' => 10]);
    dispatchInvoices(3);

    expect(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(3);
});

it('halves the concurrency of an endpoint that answered a 429 or an overloaded 5xx, at least one', function (?int $maxConcurrency, string $throttledAt, int $expectedLeases): void {
    $endpoint = throttledEndpoint(['max_concurrency' => $maxConcurrency, 'throttled_at' => $throttledAt]);
    dispatchInvoices(5);

    expect(leasesGranted($endpoint))->toBe($expectedLeases);
})->with([
    'four, throttled' => [4, '2026-11-23 09:00:00', 2],
    'five, throttled' => [5, '2026-11-23 09:00:00', 2],
    'one, throttled' => [1, '2026-11-23 09:00:00', 1],
    'the default of two, throttled' => [null, '2026-11-23 09:00:00', 1],
]);

it('gives an endpoint that is not throttled its whole concurrency', function (): void {
    expect(leasesGranted(throttledEndpoint(['max_concurrency' => 4])))->toBe(0);

    dispatchInvoices(5);

    expect(leasesGranted(WebhookEndpoint::query()->sole()))->toBe(4);
});

it('throttles an endpoint on 429, 502, 503 and 504, never on another failure, and lifts it on a success', function (int $status, bool $isThrottled): void {
    $endpoint = throttledEndpoint(['max_concurrency' => 10]);
    $this->transfer->respond($status)->respond(200);
    dispatchInvoices(1);

    app(WebhookDeliveryCoordinator::class)->work(50);

    expect($endpoint->refresh()->throttled_at?->toDateTimeString())->toBe($isThrottled ? '2026-11-23 10:00:00' : null);

    CarbonImmutable::setTestNow('2026-11-23 10:10:00');
    app(WebhookDeliveryCoordinator::class)->work(50);

    expect($endpoint->refresh()->throttled_at)->toBeNull();
})->with([
    '429' => [429, true],
    '502' => [502, true],
    '503' => [503, true],
    '504' => [504, true],
    '500' => [500, false],
    '400' => [400, false],
]);

/*
 * Review of #62: an endpoint at its limit must not take the batch, a limit of
 * 0 means none, and only the product's code sets it.
 */

it('leaves an endpoint whose window is full out of the batch, so the others still go out', function (): void {
    $limited = throttledEndpoint(['max_concurrency' => 50, 'rate_limit_per_minute' => 1, 'rate_window_started_at' => '2026-11-23 10:00:00', 'rate_window_attempts' => 1]);
    $other = throttledEndpoint(['url' => 'https://hooks.example.com/other']);
    foreach (range(1, 20) as $number) {
        app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage("msg_{$number}", 'invoice.paid', new DateTimeImmutable('2026-11-23 09:59:00'), ['n' => $number]));
    }
    WebhookDelivery::query()->where('endpoint_id', $limited->id)->update(['next_attempt_at' => '2026-11-23 09:00:00']);
    WebhookDelivery::query()->where('endpoint_id', $other->id)->where('event_id', '!=', 'msg_1')->delete();

    $otherDeliveryId = WebhookDelivery::query()->where('endpoint_id', $other->id)->sole()->id;

    // One endpoint slot in the batch: the oldest endpoint, at its limit, must not take it.
    expect(app(DatabaseDeliveryStore::class)->dueCandidates(1, 2))->toBe([$otherDeliveryId])
        ->and(app(DatabaseDeliveryStore::class)->claim(WebhookDelivery::query()->where('endpoint_id', $limited->id)->value('id'), 600, 2))->toBeNull()
        ->and(app(WebhookDeliveryCoordinator::class)->work(10))->toBe(1)
        ->and(WebhookDelivery::query()->whereKey($otherDeliveryId)->sole()->attempts_count)->toBe(1)
        ->and(app(DatabaseDeliveryStore::class)->dueCandidates(10, 2))->toBe([]);
});

it('takes no more of an endpoint into the batch than its halved concurrency and its window allow', function (array $attributes, int $expectedCandidates): void {
    throttledEndpoint($attributes);
    dispatchInvoices(6);

    expect(app(DatabaseDeliveryStore::class)->dueCandidates(50, 2))->toHaveCount($expectedCandidates);
})->with([
    'throttled, four lanes' => [['max_concurrency' => 4, 'throttled_at' => '2026-11-23 09:00:00'], 2],
    'three attempts left in the window' => [['max_concurrency' => 10, 'rate_limit_per_minute' => 5, 'rate_window_started_at' => '2026-11-23 10:00:00', 'rate_window_attempts' => 2], 3],
    'a window a minute old' => [['max_concurrency' => 10, 'rate_limit_per_minute' => 5, 'rate_window_started_at' => '2026-11-23 09:59:00', 'rate_window_attempts' => 5], 5],
    'a limit lowered below the attempts made' => [['max_concurrency' => 10, 'rate_limit_per_minute' => 2, 'rate_window_started_at' => '2026-11-23 10:00:00', 'rate_window_attempts' => 5], 0],
    'no limit, with the window claim() keeps counting' => [['max_concurrency' => 10, 'rate_window_started_at' => '2026-11-23 10:00:00', 'rate_window_attempts' => 9], 6],
    'a limit of 0, with a counted window' => [['max_concurrency' => 10, 'rate_limit_per_minute' => 0, 'rate_window_started_at' => '2026-11-23 10:00:00', 'rate_window_attempts' => 9], 6],
]);

it('refuses a claim once the window is used up, even when the limit was lowered below it', function (int $limit, int $attemptsMade): void {
    throttledEndpoint(['max_concurrency' => 10, 'rate_limit_per_minute' => $limit, 'rate_window_started_at' => '2026-11-23 10:00:00', 'rate_window_attempts' => $attemptsMade]);
    dispatchInvoices(1);

    expect(app(DatabaseDeliveryStore::class)->claim(WebhookDelivery::query()->sole()->id, 600, 2))->toBeNull();
})->with([
    'at the limit' => [3, 3],
    'above a lowered limit' => [2, 5],
]);

it('reads a rate limit of 0 as no limit', function (): void {
    throttledEndpoint(['rate_limit_per_minute' => 0, 'max_concurrency' => 10]);
    dispatchInvoices(3);

    expect(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(3);

    CarbonImmutable::setTestNow('2026-11-23 10:00:30');
    dispatchInvoices(1);

    expect(app(DatabaseDeliveryStore::class)->dueCandidates(50, 2))->toBe([]);
});

it('never mass-assigns the rate limit, which only the product code sets', function (): void {
    $endpoint = (new WebhookEndpoint)->fill(['url' => 'https://hooks.example.com/receive', 'rate_limit_per_minute' => 1]);

    expect($endpoint->isFillable('rate_limit_per_minute'))->toBeFalse()
        ->and($endpoint->getAttributes())->not->toHaveKey('rate_limit_per_minute');
});
