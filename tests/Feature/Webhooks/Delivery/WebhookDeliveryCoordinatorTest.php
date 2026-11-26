<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Contracts\LegacyWebhookProfileContract;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\EndpointDisablePolicy;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookAttemptStatusEnum;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Enums\WebhookRetryPresetEnum;
use Qiick\Outbound\Erasure\OutboundEraser;
use Qiick\Outbound\Events\WebhookDeliveredEvent;
use Qiick\Outbound\Events\WebhookDeliveryFailedEvent;
use Qiick\Outbound\Events\WebhookSignedEvent;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Retry\RetryPolicy;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Tests\Support\RecordingTransferHandler;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\SignedWebhook;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookMessage;
use Qiick\Outbound\Webhooks\WebhookSecret;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;

/*
 * Standards review M9 (the coordinator over the package tables), R4/R9 (retry
 * order and classification) and S2 (15 s webhook timeout). Every request goes
 * through the package's PinnedHttpClient; the recording transfer handler sits
 * innermost, under the pin, so it sees what really leaves.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    config()->set('outbound.webhooks.retry_jitter_seconds', 0);
    $this->transfer = new RecordingTransferHandler;
    $this->hostResolver = new FakeHostResolver(answers: [
        'hooks.example.com' => ['93.184.216.34'],
        'other.example.com' => ['93.184.216.35'],
        'internal.example.com' => ['127.0.0.1'],
    ]);
    // A fixed clock keeps the chain deadline (30 s) whole for the timeout assertions.
    $this->clock = static fn (): float => 1_000.0;
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        $this->transfer,
    ));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $eventTypes
 */
function deliveryEndpoint(array $attributes = [], array $eventTypes = ['invoice.paid'], bool $hasSecret = true): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive', ...$attributes]);
    $endpoint->save();

    foreach ($eventTypes as $eventType) {
        $endpoint->subscriptions()->create(['event_type' => $eventType]);
    }

    if ($hasSecret) {
        app(SecretRotator::class)->issue($endpoint);
    }

    return $endpoint;
}

function invoicePaidMessage(string $messageId = 'msg_2KWPBgLlAfxdpx2AI54pPJ85f4W'): WebhookMessage
{
    return new WebhookMessage(
        id: $messageId,
        type: 'invoice.paid',
        timestamp: new DateTimeImmutable('2026-11-23 09:59:00', new DateTimeZone('UTC')),
        data: ['invoice' => 'inv_1', 'amount' => 4200],
    );
}

function coordinator(): WebhookDeliveryCoordinator
{
    return app(WebhookDeliveryCoordinator::class);
}

function onlyDelivery(): WebhookDelivery
{
    return WebhookDelivery::query()->sole();
}

it('creates one pending delivery per active endpoint subscribed to the type or to every type', function (): void {
    $exact = deliveryEndpoint();
    $wildcard = deliveryEndpoint(eventTypes: ['*']);
    deliveryEndpoint(eventTypes: ['invoice.voided']);
    deliveryEndpoint(['is_active' => false]);
    deliveryEndpoint()->delete();
    $message = invoicePaidMessage();

    $deliveries = coordinator()->dispatch($message);
    $delivery = $deliveries->firstWhere('endpoint_id', $exact->id);

    expect($deliveries->pluck('endpoint_id')->sort()->values()->all())->toBe([$exact->id, $wildcard->id])
        ->and(WebhookDelivery::query()->count())->toBe(2)
        ->and($delivery?->event_id)->toBe('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W')
        ->and($delivery?->event_type)->toBe('invoice.paid')
        ->and($delivery?->occurred_at?->toDateTimeString())->toBe('2026-11-23 09:59:00')
        ->and($delivery?->payload_version)->toBe('1')
        ->and($delivery?->payload)->toBe($message->toPayloadJson())
        ->and($delivery?->payload_hash)->toBe(hash('sha256', $message->toPayloadJson()))
        ->and($delivery?->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery?->max_attempts)->toBe(10)
        ->and($delivery?->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($delivery?->fresh()?->status)->toBe(DeliveryStatus::Pending);
});

it('snapshots max attempts from the endpoint, its preset, or the configured preset', function (): void {
    config()->set('outbound.webhooks.retry_preset', 'short');
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    $configured = deliveryEndpoint();
    $standard = deliveryEndpoint(['retry_preset' => 'standard', 'payload_version' => '2']);
    $explicit = deliveryEndpoint(['max_attempts' => 3]);
    $unknownPreset = deliveryEndpoint(['retry_preset' => 'nightly']);

    coordinator()->dispatch(invoicePaidMessage());
    $deliveryOf = fn (WebhookEndpoint $endpoint): WebhookDelivery => WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->sole();

    expect($deliveryOf($configured)->max_attempts)->toBe(4)
        ->and($deliveryOf($standard)->max_attempts)->toBe(10)
        ->and($deliveryOf($standard)->payload_version)->toBe('2')
        ->and($deliveryOf($explicit)->max_attempts)->toBe(3)
        ->and($deliveryOf($unknownPreset)->max_attempts)->toBe(4);
});

it('dispatches an event once per endpoint, even when asked twice', function (): void {
    deliveryEndpoint();

    $first = coordinator()->dispatch(invoicePaidMessage());
    $second = coordinator()->dispatch(invoicePaidMessage());

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(0)
        ->and(WebhookDelivery::query()->count())->toBe(1);
});

it('fans out only to the given scope when the product names a scope column', function (): void {
    Schema::table('webhook_endpoints', function (Blueprint $table): void {
        $table->string('project_id')->nullable();
    });
    config()->set('outbound.webhooks.scope_column', 'project_id');
    // Boot built the store already (to check the payload retention).
    $this->app->forgetInstance(DatabaseDeliveryStore::class);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    $inScope = deliveryEndpoint(['project_id' => 'p1']);
    deliveryEndpoint(['project_id' => 'p2']);
    $instanceLevel = deliveryEndpoint();

    expect(coordinator()->dispatch(invoicePaidMessage('msg_a'), 'p1')->pluck('endpoint_id')->all())->toBe([$inScope->id])
        ->and(coordinator()->dispatch(invoicePaidMessage('msg_b'))->pluck('endpoint_id')->all())->toBe([$instanceLevel->id]);
});

it('refuses a scope when no scope column is configured, so a product never believes it scoped', function (): void {
    deliveryEndpoint();

    coordinator()->dispatch(invoicePaidMessage(), 'p1');
})->throws(InvalidArgumentException::class, 'A webhook scope needs outbound.webhooks.scope_column.');

it('signs the stored body with every signing secret and posts it through the pinned client', function (): void {
    $endpoint = deliveryEndpoint(['headers' => [
        'X-Tenant' => 'acme',
        'Webhook-Id' => 'msg_forged',
        'WEBHOOK-TIMESTAMP' => '1',
        'Webhook-Signature' => 'forged',
        'content-type' => 'text/plain',
    ]]);
    $oldSecret = $endpoint->secrets()->sole();
    $newSecret = app(SecretRotator::class)->rotate($endpoint);
    coordinator()->dispatch(invoicePaidMessage());

    expect(coordinator()->work(50))->toBe(1);

    $request = $this->transfer->requests[0];
    $rawBody = (string) $request->getBody();
    $verify = fn (string $secret) => app(StandardWebhookVerifier::class)->verify(
        $rawBody,
        $request->getHeaderLine('webhook-id'),
        $request->getHeaderLine('webhook-timestamp'),
        $request->getHeaderLine('webhook-signature'),
        [WebhookSecret::parse($secret)],
        new DateTimeImmutable('2026-11-23 10:00:00'),
        dedupeScope: 'test-'.$secret,
    );

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://hooks.example.com/receive')
        ->and($rawBody)->toBe(invoicePaidMessage()->toPayloadJson())
        ->and($request->getHeaderLine('webhook-id'))->toBe('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W')
        ->and($request->getHeaderLine('webhook-timestamp'))->toBe('1795428000')
        ->and($request->getHeader('webhook-id'))->toHaveCount(1)
        ->and($request->getHeader('webhook-timestamp'))->toHaveCount(1)
        ->and($request->getHeader('webhook-signature'))->toHaveCount(1)
        ->and($request->getHeaderLine('content-type'))->toBe('application/json')
        ->and($request->getHeaderLine('x-tenant'))->toBe('acme')
        ->and($this->transfer->transportOptions[0]['curl'][CURLOPT_CONNECT_TO])->toBe(['::93.184.216.34:443'])
        ->and($this->transfer->transportOptions[0]['allow_redirects'])->toBeFalse()
        ->and(fn () => $verify((string) $oldSecret->secret))->not->toThrow(Throwable::class)
        ->and(fn () => $verify((string) $newSecret->secret))->not->toThrow(Throwable::class);
});

it('records a delivered attempt and resets the endpoint failure streak', function (): void {
    $endpoint = deliveryEndpoint();
    $endpoint->forceFill(['consecutive_failures' => 4, 'failing_since' => '2026-11-22 10:00:00', 'last_failure_at' => '2026-11-23 09:00:00'])->save();
    $this->transfer->respond(204, [
        'Content-Type' => 'text/plain',
        'Retry-After' => '30',
        'X-Request-Id' => 'req-'.str_repeat('r', 76),
        'Date' => 'Mon, 23 Nov 2026 10:00:00 GMT',
        'Set-Cookie' => 'session=secret',
        'X-Internal' => 'nope',
    ], 'thanks');
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $delivery = onlyDelivery();
    $attempt = WebhookDeliveryAttempt::query()->sole();
    $endpoint->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->attempts_count)->toBe(1)
        ->and($delivery->delivered_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($delivery->last_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($delivery->last_http_status)->toBe(204)
        ->and($delivery->last_error)->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->claimed_until)->toBeNull()
        ->and($attempt->attempt)->toBe(1)
        ->and($attempt->trigger)->toBe(WebhookAttemptTriggerEnum::Schedule)
        ->and($attempt->status)->toBe(WebhookAttemptStatusEnum::Delivered)
        ->and($attempt->http_status)->toBe(204)
        ->and($attempt->error_code)->toBeNull()
        ->and($attempt->webhook_timestamp)->toBe(1_795_428_000)
        ->and($attempt->signature_schemes)->toBe('v1')
        ->and($attempt->secret_public_ids)->toBe([$endpoint->secrets()->sole()->public_id])
        ->and($attempt->retry_after_seconds)->toBe(30)
        // toEqual: MySQL's JSON type reorders object keys.
        ->and($attempt->response_headers)->toEqual([
            'content-type' => 'text/plain',
            'retry-after' => '30',
            'x-request-id' => 'req-'.str_repeat('r', 76),
            'date' => 'Mon, 23 Nov 2026 10:00:00 GMT',
        ])
        ->and($attempt->request_id)->toBe('req-'.str_repeat('r', 60))
        ->and($attempt->response_bytes)->toBe(6)
        ->and($attempt->response_excerpt)->toBeNull()
        ->and($attempt->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($attempt->attempted_at->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($attempt->next_attempt_at)->toBeNull()
        ->and($endpoint->consecutive_failures)->toBe(0)
        ->and($endpoint->failing_since)->toBeNull()
        ->and($endpoint->last_success_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($endpoint->last_failure_at?->toDateTimeString())->toBe('2026-11-23 09:00:00');
});

it('schedules a failed attempt on the Standard Webhooks delays and counts the failure streak', function (): void {
    $endpoint = deliveryEndpoint();
    $this->transfer->respond(500)->respond(500);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $afterFirst = onlyDelivery();
    CarbonImmutable::setTestNow('2026-11-23 10:00:05');
    coordinator()->work(50);
    $afterSecond = onlyDelivery();
    $attempts = WebhookDeliveryAttempt::query()->orderBy('attempt')->get();
    $endpoint->refresh();

    expect($afterFirst->status)->toBe(DeliveryStatus::Pending)
        ->and($afterFirst->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:05')
        ->and($afterFirst->last_http_status)->toBe(500)
        ->and($afterFirst->last_error)->toBe('HTTP 500')
        ->and($afterSecond->attempts_count)->toBe(2)
        ->and($afterSecond->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:05:05')
        ->and($attempts->pluck('attempt')->all())->toBe([1, 2])
        ->and($attempts[0]->status)->toBe(WebhookAttemptStatusEnum::Failed)
        ->and($attempts[0]->error_code)->toBe('http_status')
        ->and($attempts[0]->error_message)->toBe('HTTP 500')
        ->and($attempts[0]->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:05')
        ->and($endpoint->consecutive_failures)->toBe(2)
        ->and($endpoint->failing_since?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($endpoint->last_failure_at?->toDateTimeString())->toBe('2026-11-23 10:00:05')
        ->and($endpoint->last_success_at)->toBeNull();
});

it('does not send a delivery before it is due', function (): void {
    deliveryEndpoint();
    $this->transfer->respond(500);
    coordinator()->dispatch(invoicePaidMessage());
    coordinator()->work(50);

    CarbonImmutable::setTestNow('2026-11-23 10:00:04');

    expect(coordinator()->work(50))->toBe(0)
        ->and($this->transfer->requests)->toHaveCount(1);
});

it('waits at least the Retry-After of a 503', function (): void {
    deliveryEndpoint();
    $this->transfer->respond(503, ['Retry-After' => '120']);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect(onlyDelivery()->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:02:00')
        ->and(WebhookDeliveryAttempt::query()->sole()->retry_after_seconds)->toBe(120);
});

it('stops automatic retries when Retry-After exceeds the cap, and on 410 Gone', function (int $httpStatus, array $headers, WebhookExhaustedReasonEnum $reason, string $errorCode): void {
    config()->set('outbound.webhooks.retry_after_cap_seconds', 3600);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    deliveryEndpoint();
    $this->transfer->respond($httpStatus, $headers);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $delivery = onlyDelivery();

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->exhausted_reason)->toBe($reason)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->claimed_until)->toBeNull()
        ->and(WebhookDeliveryAttempt::query()->sole()->error_code)->toBe($errorCode);
})->with([
    'Retry-After above the cap' => [429, ['Retry-After' => '3601'], WebhookExhaustedReasonEnum::RetryAfterExceedsCap, 'http_status'],
    '410 Gone' => [410, [], WebhookExhaustedReasonEnum::Gone, 'gone'],
]);

it('stops after the last attempt', function (): void {
    deliveryEndpoint(['max_attempts' => 2]);
    $this->transfer->respond(500)->respond(500);
    coordinator()->dispatch(invoicePaidMessage());
    coordinator()->work(50);
    CarbonImmutable::setTestNow('2026-11-23 10:00:05');

    coordinator()->work(50);

    expect(onlyDelivery()->status)->toBe(DeliveryStatus::Exhausted)
        ->and(onlyDelivery()->exhausted_reason)->toBe(WebhookExhaustedReasonEnum::MaxAttempts)
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(2);
});

it('never follows a redirect: a 3xx is a failure', function (): void {
    deliveryEndpoint();
    $this->transfer->respond(302, ['Location' => 'https://other.example.com/']);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect($this->transfer->requests)->toHaveCount(1)
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Pending)
        ->and(WebhookDeliveryAttempt::query()->sole()->http_status)->toBe(302);
});

it('records a connection error as a failure without an HTTP status', function (): void {
    deliveryEndpoint();
    $this->transfer->failToConnect();
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $attempt = WebhookDeliveryAttempt::query()->sole();

    expect($attempt->status)->toBe(WebhookAttemptStatusEnum::Failed)
        ->and($attempt->http_status)->toBeNull()
        ->and($attempt->error_code)->toBe('connection')
        ->and($attempt->error_message)->toBe('Outbound request failed: connection error.')
        ->and(onlyDelivery()->last_http_status)->toBeNull()
        ->and(onlyDelivery()->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:05');
});

it('refuses an attempt the guard or the signer stops, sends nothing, and retries it on the schedule', function (array $endpointAttributes, bool $hasSecret, string $errorCode, string $errorMessage): void {
    // Signed attempts record their secrets even when the guard stops them.
    $endpoint = deliveryEndpoint($endpointAttributes, hasSecret: $hasSecret);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $attempt = WebhookDeliveryAttempt::query()->sole();

    expect($this->transfer->requests)->toBe([])
        ->and($attempt->status)->toBe(WebhookAttemptStatusEnum::Refused)
        ->and($attempt->error_code)->toBe($errorCode)
        ->and($attempt->error_message)->toBe($errorMessage)
        ->and($attempt->secret_public_ids)->toBe($hasSecret ? [$endpoint->secrets()->sole()->public_id] : null)
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Pending)
        ->and(onlyDelivery()->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:05')
        // A refusal on the package's side is not the receiver's failure.
        ->and($endpoint->refresh()->consecutive_failures)->toBe(0)
        ->and($endpoint->failing_since)->toBeNull();
})->with([
    'private address' => [['url' => 'https://internal.example.com/hook'], true, 'ssrf_refused', 'Host resolves to a disallowed address.'],
    'no signing secret' => [[], false, 'signing', 'Webhook endpoint has no active primary signing secret.'],
    'refused header' => [['headers' => ['Host' => 'evil.example.com']], true, 'request_refused', 'Outbound request must not set Host or routing headers.'],
]);

it('refuses an attempt whose body has been pruned', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    onlyDelivery()->forceFill(['payload' => null])->save();

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole()->error_code)->toBe('signing')
        ->and(WebhookDeliveryAttempt::query()->sole()->error_message)->toBe('Webhook delivery body has been pruned.');
});

it('posts with a 15 s timeout, an endpoint override, and never above the configured maximum', function (?int $endpointTimeout, int $expectedTimeout): void {
    deliveryEndpoint(['timeout_seconds' => $endpointTimeout]);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect($this->transfer->transportOptions[0]['timeout'])->toBe($expectedTimeout)
        ->and($this->transfer->transportOptions[0]['connect_timeout'])->toBe(3);
})->with([
    'webhook default' => [null, 15],
    'endpoint override' => [20, 20],
    'endpoint above the 60 s maximum, then bounded by the 30 s chain deadline' => [120, 30],
    'endpoint at 45 s, bounded by the 30 s chain deadline' => [45, 30],
]);

it('applies the endpoint https override to the guard', function (): void {
    deliveryEndpoint(['url' => 'http://hooks.example.com/plain', 'https_only' => true]);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole()->error_code)->toBe('ssrf_refused')
        ->and($this->transfer->requests)->toBe([]);
});

it('keeps each endpoint within its concurrency, counting deliveries other workers hold', function (): void {
    $single = deliveryEndpoint(['max_concurrency' => 1]);
    $default = deliveryEndpoint(['url' => 'https://other.example.com/receive']);
    foreach (['msg_1', 'msg_2', 'msg_3'] as $messageId) {
        coordinator()->dispatch(invoicePaidMessage($messageId));
    }

    expect(coordinator()->work(50))->toBe(3)
        ->and(WebhookDelivery::query()->where('endpoint_id', $single->id)->where('status', 'delivered')->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('endpoint_id', $default->id)->where('status', 'delivered')->count())->toBe(2);

    WebhookDelivery::query()->where('endpoint_id', $single->id)->where('status', 'pending')->limit(1)->update(['claimed_until' => '2026-11-23 10:01:00']);

    expect(coordinator()->work(50))->toBe(1)
        ->and(WebhookDelivery::query()->where('endpoint_id', $single->id)->where('status', 'pending')->count())->toBe(2);
});

it('takes a delivery back once a crashed worker\'s lease expires', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    onlyDelivery()->forceFill(['claimed_until' => '2026-11-23 10:01:30'])->save();

    expect(coordinator()->work(50))->toBe(0);

    CarbonImmutable::setTestNow('2026-11-23 10:01:30');

    expect(coordinator()->work(50))->toBe(1)
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Delivered);
});

it('holds a lease of the chain deadline plus a minute while it sends', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $leaseSeenBySender = null;
    $this->transfer->respond(200);
    $recordLease = function () use (&$leaseSeenBySender): void {
        $leaseSeenBySender = WebhookDelivery::query()->sole()->claimed_until?->toDateTimeString();
    };
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) use ($recordLease) {
            $recordLease();

            return ($this->transfer)($request, $options);
        },
    ));

    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    coordinator()->work(50);

    expect($leaseSeenBySender)->toBe('2026-11-23 10:01:30');
});

it('claims at most the given number of due deliveries, oldest first', function (): void {
    deliveryEndpoint(['max_concurrency' => 10]);
    coordinator()->dispatch(invoicePaidMessage('msg_now'));
    coordinator()->dispatch(invoicePaidMessage('msg_late'));
    WebhookDelivery::query()->where('event_id', 'msg_late')->update(['next_attempt_at' => '2026-11-23 09:58:00']);

    expect(coordinator()->work(limit: 1))->toBe(1)
        ->and(WebhookDelivery::query()->where('event_id', 'msg_late')->sole()->status)->toBe(DeliveryStatus::Delivered)
        ->and(WebhookDelivery::query()->where('event_id', 'msg_now')->sole()->status)->toBe(DeliveryStatus::Pending);
});

it('skips deliveries of endpoints that are disabled or deleted, and settled deliveries', function (): void {
    $disabled = deliveryEndpoint();
    $deleted = deliveryEndpoint(['url' => 'https://other.example.com/receive']);
    coordinator()->dispatch(invoicePaidMessage());
    $disabled->forceFill(['is_active' => false])->save();
    $deleted->delete();

    expect(coordinator()->work(50))->toBe(0);

    $delivered = deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage('msg_settled'));
    WebhookDelivery::query()->where('endpoint_id', $delivered->id)->update(['status' => 'delivered']);

    expect(coordinator()->work(50))->toBe(0)
        ->and($this->transfer->requests)->toBe([]);
});

it('works through due deliveries from the outbound:webhooks:work command', function (): void {
    deliveryEndpoint(['max_concurrency' => 5]);
    coordinator()->dispatch(invoicePaidMessage('msg_1'));
    coordinator()->dispatch(invoicePaidMessage('msg_2'));

    $this->artisan('outbound:webhooks:work', ['--limit' => 1])
        ->expectsOutput('Sent 1 webhook delivery attempt(s).')
        ->assertSuccessful();
    $this->artisan('outbound:webhooks:work')
        ->expectsOutput('Sent 1 webhook delivery attempt(s).')
        ->assertSuccessful();
});

it('refuses a limit below one', function (): void {
    $this->artisan('outbound:webhooks:work', ['--limit' => 0])
        ->expectsOutput('The --limit option must be a positive integer.')
        ->assertFailed();
});

it('cuts kept response header values at 255 characters and keeps none when there are none', function (): void {
    deliveryEndpoint(['max_concurrency' => 5]);
    $this->transfer->respond(200, ['Content-Type' => str_repeat('a', 300)])->respond(200);
    coordinator()->dispatch(invoicePaidMessage('msg_1'));
    coordinator()->dispatch(invoicePaidMessage('msg_2'));

    coordinator()->work(50);
    $attempts = WebhookDeliveryAttempt::query()->orderBy('id')->get();

    expect($attempts[0]->response_headers)->toBe(['content-type' => str_repeat('a', 255)])
        ->and($attempts[1]->response_headers)->toBe([])
        ->and($attempts[1]->request_id)->toBeNull()
        ->and($attempts[1]->response_bytes)->toBe(0)
        ->and($attempts[1]->retry_after_seconds)->toBeNull();
});

it('measures the attempt duration in whole milliseconds on a monotonic clock', function (): void {
    deliveryEndpoint();
    deliveryEndpoint(['url' => 'https://other.example.com/receive']);
    coordinator()->dispatch(invoicePaidMessage());
    // 87.999999 ms and exactly 88 ms: whole milliseconds, rounded down.
    $readings = [5_000_000_000, 5_087_999_999, 6_000_000_000, 6_088_000_000];
    $coordinator = new WebhookDeliveryCoordinator(
        app(DatabaseDeliveryStore::class),
        app(WebhookSecretResolver::class),
        app(StandardWebhookSigner::class),
        app(PinnedHttpClient::class),
        app(RetryPolicy::class),
        app(OutboundHttpLimits::class),
        timeoutSeconds: 15,
        connectTimeoutSeconds: 3,
        defaultRetryPreset: WebhookRetryPresetEnum::Standard,
        defaultMaxConcurrency: 2,
        maxResponseBytes: 1_048_576,
        exceptionHandler: app(ExceptionHandler::class),
        disablePolicy: app(EndpointDisablePolicy::class),
        events: app(Dispatcher::class),
        nanosecondClock: function () use (&$readings): int {
            return (int) array_shift($readings);
        },
    );

    $coordinator->work(50);

    expect(WebhookDeliveryAttempt::query()->orderBy('id')->pluck('duration_ms')->all())->toBe([87, 88]);
});

it('drops its result when another worker recorded this attempt after the lease ran out', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) {
            // Another worker took the expired lease and recorded attempt 1 first.
            WebhookDelivery::query()->update(['attempts_count' => 1]);

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    expect(coordinator()->work(50))->toBe(1)
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(0)
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Pending)
        ->and(onlyDelivery()->claimed_until)->not->toBeNull()
        ->and(WebhookEndpoint::query()->sole()->last_success_at)->toBeNull();
});

it('ignores Retry-After when the configuration turns it off', function (): void {
    config()->set('outbound.webhooks.respect_retry_after', false);
    $this->app->forgetInstance(RetryPolicy::class);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    deliveryEndpoint();
    $this->transfer->respond(503, ['Retry-After' => '120']);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect(onlyDelivery()->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:05');
});

it('treats an empty scope column setting as instance scope', function (): void {
    config()->set('outbound.webhooks.scope_column', '');
    $this->app->forgetInstance(DatabaseDeliveryStore::class);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    deliveryEndpoint();

    expect(fn () => coordinator()->dispatch(invoicePaidMessage(), 'p1'))
        ->toThrow(InvalidArgumentException::class, 'A webhook scope needs outbound.webhooks.scope_column.')
        ->and(coordinator())->toBe(coordinator());
});

it('refuses a work limit below one', function (): void {
    coordinator()->work(0);
})->throws(InvalidArgumentException::class, 'The work limit must be at least 1.');

it('claims nothing for an endpoint already over its concurrency', function (): void {
    $endpoint = deliveryEndpoint(['max_concurrency' => 1]);
    foreach (['msg_1', 'msg_2', 'msg_3'] as $messageId) {
        coordinator()->dispatch(invoicePaidMessage($messageId));
    }
    WebhookDelivery::query()->whereIn('event_id', ['msg_1', 'msg_2'])->update(['claimed_until' => '2026-11-23 10:01:00']);

    expect(coordinator()->work(50))->toBe(0)
        ->and($this->transfer->requests)->toBe([]);
});

it('records nothing for a delivery deleted while it was being sent', function (): void {
    $endpoint = deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) {
            WebhookDelivery::query()->delete();

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    expect(coordinator()->work(50))->toBe(1)
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(0)
        ->and($endpoint->refresh()->last_success_at)->toBeNull();
});

it('keeps a delivery cancelled while it was being sent cancelled, with no attempt recorded', function (): void {
    Event::fake([WebhookDeliveredEvent::class]);
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) {
            // The eraser cancels the person's pending delivery after the claim.
            WebhookDelivery::query()->update(['status' => DeliveryStatus::Cancelled->value, 'payload' => null]);

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    coordinator()->work(50);

    expect(onlyDelivery()->status)->toBe(DeliveryStatus::Cancelled)
        ->and(onlyDelivery()->delivered_at)->toBeNull()
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(0);
    Event::assertNotDispatched(WebhookDeliveredEvent::class);
});

it('never sends a claimed delivery that is cancelled before its transport starts', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    // The eraser commits its cancel between this worker's claim and its send.
    Event::listen(WebhookSignedEvent::class, function (): void {
        WebhookDelivery::query()->update(['status' => DeliveryStatus::Cancelled->value, 'payload' => null]);
    });

    coordinator()->work(50);

    expect($this->transfer->requests)->toBe([])
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Cancelled)
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(0);
});

it('never sends a delivery cancelled while its host was being resolved', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $resolver = new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            // The eraser commits its cancel while DNS answers.
            WebhookDelivery::query()->update(['status' => DeliveryStatus::Cancelled->value, 'payload' => null]);

            return ['93.184.216.34'];
        }
    };
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($resolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        $this->transfer,
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    coordinator()->work(50);

    expect($this->transfer->requests)->toBe([])
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Cancelled)
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(0);
});

it('never claims or sends a delivery whose body has expired, before prune runs', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    onlyDelivery()->forceFill(['payload_expires_at' => CarbonImmutable::now()->subSecond()])->save();

    expect(coordinator()->work(50))->toBe(0)
        ->and($this->transfer->requests)->toBe([])
        ->and(onlyDelivery()->claimed_until)->toBeNull();
});

it('never sends a body that expires between the claim and the transport', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    Event::listen(WebhookSignedEvent::class, function (): void {
        WebhookDelivery::query()->update(['payload_expires_at' => CarbonImmutable::now()->subSecond()]);
    });

    coordinator()->work(50);

    expect($this->transfer->requests)->toBe([]);
});

it('records the asker the claim stored, and clears it from the delivery once recorded', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $askerId = (int) DB::table('users')->insertGetId(['name' => 'Asker', 'email' => 'asker@example.com', 'password' => 'x']);
    onlyDelivery()->forceFill(['next_attempt_trigger' => 'manual', 'next_attempt_triggered_by' => $askerId])->save();

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole()->triggered_by)->toBe($askerId)
        ->and(onlyDelivery()->claimed_triggered_by)->toBeNull();
});

it('does not attribute an attempt to an asker erased while it was being sent', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage(), subjectPublicId: '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11');
    $askerId = (int) DB::table('users')->insertGetId(['name' => 'Asker', 'email' => 'asker@example.com', 'password' => 'x']);
    onlyDelivery()->forceFill(['next_attempt_trigger' => 'manual', 'next_attempt_triggered_by' => $askerId])->save();
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) use ($askerId) {
            // The asker is erased; the delivery is someone else's, so it goes on.
            app(OutboundEraser::class)->erase((string) $askerId, (string) Str::uuid(), OutboundEraser::DELETE);

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole())
        ->triggered_by->toBeNull()
        ->trigger->toBe(WebhookAttemptTriggerEnum::Manual)
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Delivered);
});

it('never writes back an asker the eraser unlinked, even when a new redelivery is asked while it is sent', function (): void {
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $firstAskerId = (int) DB::table('users')->insertGetId(['name' => 'First', 'email' => 'first@example.com', 'password' => 'x']);
    $secondAskerId = (int) DB::table('users')->insertGetId(['name' => 'Second', 'email' => 'second@example.com', 'password' => 'x']);
    onlyDelivery()->forceFill(['next_attempt_trigger' => 'manual', 'next_attempt_triggered_by' => $firstAskerId])->save();
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) use ($firstAskerId, $secondAskerId) {
            // The first asker is erased, then a second one asks again.
            app(OutboundEraser::class)->erase((string) $firstAskerId, (string) Str::uuid(), OutboundEraser::DELETE);
            coordinator()->redeliver(onlyDelivery(), $secondAskerId);

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole()->triggered_by)->toBeNull()
        ->and(onlyDelivery()->next_attempt_triggered_by)->toBe($secondAskerId);

});

it('stores the subject public id the product names at dispatch, and only a UUID', function (): void {
    deliveryEndpoint();
    $subjectPublicId = '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11';

    coordinator()->dispatch(invoicePaidMessage(), subjectPublicId: strtoupper($subjectPublicId));

    // Stored in lower case, the spelling the eraser compares.
    expect(onlyDelivery()->subject_public_id)->toBe($subjectPublicId)
        ->and(onlyDelivery()->toArray())->not->toHaveKey('subject_public_id')
        ->and(fn () => coordinator()->dispatch(invoicePaidMessage('msg_other'), subjectPublicId: 'user-42'))
        ->toThrow(InvalidArgumentException::class, 'The subject public id must be a UUID.');
});

it('stores no subject public id when the product names none', function (): void {
    deliveryEndpoint();

    coordinator()->dispatch(invoicePaidMessage());

    expect(onlyDelivery()->subject_public_id)->toBeNull();
});

it('sends to a plain-http URL when neither the endpoint nor the config asks for https', function (): void {
    deliveryEndpoint(['url' => 'http://hooks.example.com/plain']);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect((string) $this->transfer->requests[0]->getUri())->toBe('http://hooks.example.com/plain')
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Delivered);
});

/*
 * Review of #25 (2026-11-23): receiver-controlled text, overlapping workers,
 * fairness between endpoints, and the per-endpoint private-host switch.
 */

it('stores a receiver\'s request id and header values as valid, bounded text', function (string $requestId, ?string $expectedRequestId): void {
    deliveryEndpoint();
    $this->transfer->respond(500, ['X-Request-Id' => $requestId, 'Content-Type' => str_repeat('é', 254)."\xff\u{202E}é", 'Date' => "Mon\u{202E}day\xff"]);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $attempt = WebhookDeliveryAttempt::query()->sole();
    $contentType = (string) ($attempt->response_headers['content-type'] ?? '');

    expect($attempt->request_id)->toBe($expectedRequestId)
        ->and(mb_check_encoding($contentType, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($contentType))->toBe(255)
        ->and($contentType)->toBe(str_repeat('é', 254).'?')
        ->and($attempt->response_headers['date'] ?? null)->toBe('Monday?')
        ->and(onlyDelivery()->attempts_count)->toBe(1);
})->with([
    'cut inside a two-byte character' => [str_repeat('a', 63).'é', str_repeat('a', 63)],
    'invalid bytes' => ["req-\xff\xfe-1", 'req--1'],
    'markup' => ['<script>alert(1)</script>', 'scriptalert1script'],
    'nothing usable' => ['é', null],
]);

it('keeps a 2xx delivered when the full attempt row cannot be stored', function (): void {
    deliveryEndpoint();
    $this->transfer->respond(204, ['X-Request-Id' => 'req-1']);
    coordinator()->dispatch(invoicePaidMessage());
    WebhookDeliveryAttempt::creating(function (WebhookDeliveryAttempt $attempt): void {
        if ($attempt->request_id !== null) {
            throw new RuntimeException('Incorrect string value for column request_id');
        }
    });

    expect(coordinator()->work(50))->toBe(1)
        ->and(onlyDelivery()->status)->toBe(DeliveryStatus::Delivered)
        ->and(onlyDelivery()->attempts_count)->toBe(1)
        ->and(WebhookDeliveryAttempt::query()->sole()->status)->toBe(WebhookAttemptStatusEnum::Delivered)
        ->and(WebhookDeliveryAttempt::query()->sole()->http_status)->toBe(204)
        ->and(WebhookDeliveryAttempt::query()->sole()->response_headers)->toBeNull();
});

it('records an unexpected error as a refused attempt and carries on with the batch', function (): void {
    $broken = deliveryEndpoint(['headers' => ['X-List' => ['a', 'b']]]);
    $healthy = deliveryEndpoint(['url' => 'https://other.example.com/receive']);
    coordinator()->dispatch(invoicePaidMessage());

    expect(coordinator()->work(50))->toBe(2);

    $brokenDelivery = WebhookDelivery::query()->where('endpoint_id', $broken->id)->sole();
    $brokenAttempt = $brokenDelivery->attempts()->sole();

    expect($brokenAttempt->status)->toBe(WebhookAttemptStatusEnum::Refused)
        ->and($brokenAttempt->error_code)->toBe('internal_error')
        ->and($brokenAttempt->error_message)->toBe('The attempt could not be prepared.')
        ->and($brokenDelivery->attempts_count)->toBe(1)
        ->and($brokenDelivery->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:05')
        ->and(WebhookDelivery::query()->where('endpoint_id', $healthy->id)->sole()->status)->toBe(DeliveryStatus::Delivered);
});

it('never sends a delivery twice when a second worker starts while the first is still sending', function (): void {
    $endpointNames = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
    $resolverAnswers = [];
    foreach ($endpointNames as $endpointName) {
        $resolverAnswers["{$endpointName}.example.com"] = ['93.184.216.34'];
    }
    $sentHosts = [];
    $secondWorkerHasRun = false;
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: $resolverAnswers)),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) use (&$sentHosts, &$secondWorkerHasRun) {
            $sentHosts[] = explode('.', $request->getUri()->getHost())[0];
            // Every send takes the full 15 s webhook timeout.
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(15));

            if (count($sentHosts) === 7 && ! $secondWorkerHasRun) {
                $secondWorkerHasRun = true;
                app(WebhookDeliveryCoordinator::class)->work(50);
            }

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    foreach ($endpointNames as $endpointName) {
        deliveryEndpoint(['url' => "https://{$endpointName}.example.com/receive"]);
    }
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect($sentHosts)->toEqualCanonicalizing($endpointNames)
        ->and(WebhookDelivery::query()->where('status', 'delivered')->count())->toBe(8)
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(8);
});

it('gives every endpoint its turn when another has a large backlog', function (): void {
    $busy = deliveryEndpoint();
    $quiet = deliveryEndpoint(['url' => 'https://other.example.com/receive'], ['invoice.voided']);
    foreach (range(1, 60) as $messageNumber) {
        coordinator()->dispatch(invoicePaidMessage("msg_{$messageNumber}"));
    }
    WebhookDelivery::query()->update(['next_attempt_at' => '2026-11-23 09:00:00']);
    coordinator()->dispatch(new WebhookMessage('msg_quiet', 'invoice.voided', new DateTimeImmutable('2026-11-23 09:59:00'), []));

    expect(coordinator()->work(50))->toBe(3)
        ->and(WebhookDelivery::query()->where('endpoint_id', $quiet->id)->sole()->status)->toBe(DeliveryStatus::Delivered)
        ->and(WebhookDelivery::query()->where('endpoint_id', $busy->id)->where('status', 'delivered')->count())->toBe(2);
});

it('does not send to an endpoint disabled after the batch was selected', function (): void {
    $first = deliveryEndpoint();
    $second = deliveryEndpoint(['url' => 'https://other.example.com/receive']);
    coordinator()->dispatch(invoicePaidMessage());
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) use ($second) {
            WebhookEndpoint::query()->whereKey($second->id)->update(['is_active' => false]);

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    expect(coordinator()->work(50))->toBe(1)
        ->and($this->transfer->requests)->toHaveCount(1)
        ->and(WebhookDelivery::query()->where('endpoint_id', $first->id)->sole()->status)->toBe(DeliveryStatus::Delivered);
});

it('reaches private hosts only for an endpoint set to, under a global setting that allows them', function (bool $isAllowedGlobally, ?bool $endpointSetting, ?string $errorCode): void {
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver, allowPrivateHosts: $isAllowedGlobally),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        $this->transfer,
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    deliveryEndpoint(['url' => 'https://internal.example.com/hook', ...($endpointSetting === null ? [] : ['allow_private_hosts' => $endpointSetting])]);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole()->error_code)->toBe($errorCode);
})->with([
    // A later change of the global setting must not open every endpoint left at its default.
    'allowed globally, the endpoint at its default' => [true, null, 'ssrf_refused'],
    'allowed globally, the endpoint refuses' => [true, false, 'ssrf_refused'],
    'allowed globally, the endpoint set to' => [true, true, null],
    'refused globally, the endpoint cannot widen' => [false, true, 'ssrf_refused'],
    'refused globally, the endpoint at its default' => [false, null, 'ssrf_refused'],
]);

it('never lets input set an endpoint private-host setting', function (): void {
    $endpoint = deliveryEndpoint(['url' => 'https://internal.example.com/hook']);
    $endpoint->fill(['allow_private_hosts' => true])->save();

    expect($endpoint->refresh()->allow_private_hosts)->toBeFalse();
});

it('honours the first Retry-After when the header is repeated', function (): void {
    deliveryEndpoint();
    $this->transfer->respond(503, ['Retry-After' => ['120', '120']]);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect(onlyDelivery()->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:02:00');
});

it('records which secrets signed an attempt whose transport failed after sending', function (): void {
    $endpoint = deliveryEndpoint();
    $this->transfer->failToConnect();
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole()->secret_public_ids)->toBe([$endpoint->secrets()->sole()->public_id]);
});

/**
 * An exception handler that only remembers what it was asked to report.
 */
function reportingSpy(): ExceptionHandler
{
    return new class implements ExceptionHandler
    {
        /** @var list<Throwable> */
        public array $reported = [];

        public function report(Throwable $e): void
        {
            $this->reported[] = $e;
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    };
}

it('reports a delivery whose attempt cannot be stored at all, and carries on with the batch', function (): void {
    $spy = reportingSpy();
    $this->app->instance(ExceptionHandler::class, $spy);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    $unrecordable = deliveryEndpoint();
    $healthy = deliveryEndpoint(['url' => 'https://other.example.com/receive']);
    coordinator()->dispatch(invoicePaidMessage());
    WebhookDeliveryAttempt::creating(function (WebhookDeliveryAttempt $attempt) use ($unrecordable): void {
        if ($attempt->delivery->endpoint_id === $unrecordable->id) {
            throw new RuntimeException('The database is gone.');
        }
    });

    expect(coordinator()->work(50))->toBe(2)
        ->and($spy->reported)->toHaveCount(1)
        ->and($spy->reported[0]->getMessage())->toBe('The database is gone.')
        ->and(WebhookDelivery::query()->where('endpoint_id', $healthy->id)->sole()->status)->toBe(DeliveryStatus::Delivered);
});

it('reports nothing for a delivery deleted while it was being sent', function (): void {
    $spy = reportingSpy();
    $this->app->instance(ExceptionHandler::class, $spy);
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard($this->hostResolver),
        $this->app->make(OutboundHttpLimits::class),
        $this->clock,
        function ($request, array $options) {
            WebhookDelivery::query()->delete();

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    coordinator()->work(50);

    expect($spy->reported)->toBe([]);
});

it('sends equally due deliveries of two endpoints in delivery id order', function (): void {
    deliveryEndpoint(eventTypes: ['invoice.voided']);
    deliveryEndpoint(['url' => 'https://other.example.com/receive']);
    // The second endpoint's delivery is created first, so it has the lower id.
    coordinator()->dispatch(invoicePaidMessage());
    coordinator()->dispatch(new WebhookMessage('msg_voided', 'invoice.voided', new DateTimeImmutable('2026-11-23 09:59:00'), []));
    WebhookDelivery::query()->update(['next_attempt_at' => '2026-11-23 09:00:00']);

    coordinator()->work(50);

    expect(array_map(static fn ($request): string => $request->getUri()->getHost(), $this->transfer->requests))
        ->toBe(['other.example.com', 'hooks.example.com']);
});

it('records the kind of a transport error as the attempt error code', function (int $curlErrno, string $errorCode, string $errorMessage): void {
    deliveryEndpoint();
    $this->transfer->failWithCurlError($curlErrno);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $attempt = WebhookDeliveryAttempt::query()->sole();

    expect($attempt->status)->toBe(WebhookAttemptStatusEnum::Failed)
        ->and($attempt->error_code)->toBe($errorCode)
        ->and($attempt->error_message)->toBe($errorMessage);
})->with([
    'connection refused' => [7, 'connection', 'Outbound request failed: connection error.'],
    'timeout' => [28, 'timeout', 'Outbound request failed: timeout.'],
    'TLS' => [35, 'tls', 'Outbound request failed: TLS error.'],
    'DNS' => [6, 'dns', 'Outbound request failed: DNS error.'],
]);

it('keeps "transport" for a client refusal that has no transport kind', function (): void {
    config()->set('outbound.http.max_response_bytes', 10);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    deliveryEndpoint();
    $this->transfer->respond(200, ['Content-Length' => '100'], str_repeat('a', 100));
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect(WebhookDeliveryAttempt::query()->sole()->error_code)->toBe('transport')
        ->and(WebhookDeliveryAttempt::query()->sole()->error_message)->toBe('Response exceeds max_response_bytes.');
});

/*
 * Standards review S5: an exhausted delivery is announced, public ids and the
 * reason only.
 */

it('announces a delivery the attempt left exhausted, and only then', function (): void {
    $failedEvents = [];
    app(Dispatcher::class)->listen(WebhookDeliveryFailedEvent::class, function (WebhookDeliveryFailedEvent $event) use (&$failedEvents): void {
        $failedEvents[] = $event;
    });
    $endpoint = deliveryEndpoint(['max_attempts' => 2]);
    $this->transfer->respond(500)->respond(500);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect($failedEvents)->toBe([]);

    CarbonImmutable::setTestNow('2026-11-23 10:00:05');
    coordinator()->work(50);

    expect($failedEvents)->toHaveCount(1)
        ->and($failedEvents[0]->deliveryPublicId)->toBe(onlyDelivery()->public_id)
        ->and($failedEvents[0]->endpointPublicId)->toBe($endpoint->public_id)
        ->and($failedEvents[0]->eventType)->toBe('invoice.paid')
        ->and($failedEvents[0]->reason)->toBe(WebhookExhaustedReasonEnum::MaxAttempts)
        ->and($failedEvents[0]->attempts)->toBe(2)
        ->and($failedEvents[0]->failedAt->toDateTimeString())->toBe('2026-11-23 10:00:05');
});

it('announces a delivery a 410 Gone exhausted, with that reason', function (): void {
    $failedEvents = [];
    app(Dispatcher::class)->listen(WebhookDeliveryFailedEvent::class, function (WebhookDeliveryFailedEvent $event) use (&$failedEvents): void {
        $failedEvents[] = $event;
    });
    deliveryEndpoint();
    $this->transfer->respond(410);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect($failedEvents)->toHaveCount(1)
        ->and($failedEvents[0]->reason)->toBe(WebhookExhaustedReasonEnum::Gone)
        ->and($failedEvents[0]->attempts)->toBe(1);
});

/*
 * Standards review S8: a legacy profile adds its own body keys and headers
 * without a condition in the package.
 */

/**
 * @param  array<string, string>  $extraHeaders
 */
function registerLegacyProfile(array $extraHeaders = []): void
{
    app()->instance('test.legacy-profile', new class($extraHeaders) implements LegacyWebhookProfileContract
    {
        /** @param  array<string, string>  $extraHeaders */
        public function __construct(private array $extraHeaders) {}

        public function body(WebhookMessage $message, string $standardBody): string
        {
            return json_encode(['id' => $message->id, ...json_decode($standardBody, true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        public function headers(SignedWebhook $signedWebhook, array $secrets): array
        {
            return [
                'X-Legacy-Event-Id' => $signedWebhook->headers['webhook-id'],
                'X-Legacy-Signature' => hash_hmac('sha256', $signedWebhook->rawBody, $secrets[0]->signingKey()),
                ...$this->extraHeaders,
            ];
        }
    });
    config()->set('outbound.webhooks.legacy_profiles', ['legacy-1' => 'test.legacy-profile']);
    app()->forgetInstance(WebhookDeliveryCoordinator::class);
}

it('stores, signs and sends the legacy body, with the legacy headers next to the Standard Webhooks ones', function (): void {
    registerLegacyProfile();
    $endpoint = deliveryEndpoint(['legacy_profile' => 'legacy-1']);
    $secret = $endpoint->secrets()->sole();
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $request = $this->transfer->requests[0];
    $rawBody = (string) $request->getBody();
    $expectedBody = json_encode(['id' => 'msg_2KWPBgLlAfxdpx2AI54pPJ85f4W', ...json_decode(invoicePaidMessage()->toPayloadJson(), true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect(onlyDelivery()->payload)->toBe($expectedBody)
        ->and($rawBody)->toBe($expectedBody)
        ->and($request->getHeaderLine('x-legacy-event-id'))->toBe('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W')
        ->and($request->getHeaderLine('x-legacy-signature'))->toBe(hash_hmac('sha256', $expectedBody, WebhookSecret::parse((string) $secret->secret)->signingKey()))
        ->and(fn () => app(StandardWebhookVerifier::class)->verify($rawBody, $request->getHeaderLine('webhook-id'), $request->getHeaderLine('webhook-timestamp'), $request->getHeaderLine('webhook-signature'), [WebhookSecret::parse((string) $secret->secret)], new DateTimeImmutable('2026-11-23 10:00:00'), 'legacy'))->not->toThrow(Throwable::class);
});

it('sends the plain body and no legacy header to an endpoint without a profile', function (): void {
    registerLegacyProfile();
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect((string) $this->transfer->requests[0]->getBody())->toBe(invoicePaidMessage()->toPayloadJson())
        ->and($this->transfer->requests[0]->hasHeader('x-legacy-signature'))->toBeFalse();
});

it('refuses every attempt to an endpoint whose profile is not registered, or that names a Standard Webhooks header', function (?string $profile, array $extraHeaders, string $message, bool $isBodyPlain): void {
    registerLegacyProfile($extraHeaders);
    deliveryEndpoint(['legacy_profile' => $profile]);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);
    $attempt = WebhookDeliveryAttempt::query()->sole();

    expect($this->transfer->requests)->toBe([])
        ->and($attempt->status)->toBe(WebhookAttemptStatusEnum::Refused)
        ->and($attempt->error_code)->toBe('legacy_profile')
        ->and($attempt->error_message)->toBe($message)
        ->and(onlyDelivery()->payload === invoicePaidMessage()->toPayloadJson())->toBe($isBodyPlain);
})->with([
    'an unknown profile, which keeps the plain body' => ['legacy-9', [], 'The endpoint names a legacy profile that is not registered.', true],
    'a profile setting webhook-signature' => ['legacy-1', ['Webhook-Signature' => 'v1,forged'], 'A legacy profile must not set a Standard Webhooks header.', false],
    'a profile setting it in another case' => ['legacy-1', ['WEBHOOK-ID' => 'msg_forged'], 'A legacy profile must not set a Standard Webhooks header.', false],
    'a profile setting webhook-timestamp' => ['legacy-1', ['Webhook-Timestamp' => '1'], 'A legacy profile must not set a Standard Webhooks header.', false],
]);

it('refuses a legacy profile entry that is not a name and a profile binding', function (mixed $profiles): void {
    config()->set('outbound.webhooks.legacy_profiles', $profiles);
    app()->instance('test.not-a-profile', new stdClass);
    app()->forgetInstance(WebhookDeliveryCoordinator::class);

    expect(fn () => coordinator())->toThrow(InvalidArgumentException::class, 'outbound.webhooks.legacy_profiles must map a name to a LegacyWebhookProfileContract binding.');
})->with([
    'not a profile' => [['legacy-1' => 'test.not-a-profile']],
    'not a binding name' => [['legacy-1' => 42]],
    'no name' => [['test.legacy-profile']],
    'not a map at all' => ['test.legacy-profile'],
]);

/*
 * Excubia adoption: a delivered webhook is announced, like an exhausted one.
 */

/**
 * @param  list<WebhookDeliveredEvent>  $deliveredEvents  filled as the events arrive
 */
function listenForDeliveredEvents(array &$deliveredEvents): void
{
    app(Dispatcher::class)->listen(WebhookDeliveredEvent::class, function (WebhookDeliveredEvent $event) use (&$deliveredEvents): void {
        $deliveredEvents[] = $event;
    });
}

it('announces a delivered webhook with its ids and the attempt that delivered it', function (): void {
    $deliveredEvents = [];
    listenForDeliveredEvents($deliveredEvents);
    $endpoint = deliveryEndpoint();
    $this->transfer->respond(503)->respond(200);
    coordinator()->dispatch(invoicePaidMessage());

    coordinator()->work(50);

    expect($deliveredEvents)->toBe([]);

    CarbonImmutable::setTestNow('2026-11-23 10:00:05');
    coordinator()->work(50);

    expect($deliveredEvents)->toHaveCount(1)
        ->and($deliveredEvents[0]->deliveryPublicId)->toBe(onlyDelivery()->public_id)
        ->and($deliveredEvents[0]->eventId)->toBe('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W')
        ->and($deliveredEvents[0]->endpointPublicId)->toBe($endpoint->public_id)
        ->and($deliveredEvents[0]->attempt)->toBe(2)
        ->and($deliveredEvents[0]->deliveredAt->toDateTimeString())->toBe('2026-11-23 10:00:05')
        ->and($deliveredEvents[0])->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('announces no delivery that an outer transaction rolls back', function (): void {
    $deliveredEvents = [];
    listenForDeliveredEvents($deliveredEvents);
    deliveryEndpoint();
    coordinator()->dispatch(invoicePaidMessage());

    try {
        DB::transaction(function (): void {
            coordinator()->work(50);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect($this->transfer->requests)->toHaveCount(1)
        ->and($deliveredEvents)->toBe([]);
});

/*
 * Signaculum adoption: a run bounded in time as well as in count.
 */

/**
 * @param  list<int>|null  $readings  nanosecond readings in order; else one second per read
 */
function coordinatorOnAClockThatTicksPerRead(?array $readings = null): WebhookDeliveryCoordinator
{
    $seconds = 0;

    return new WebhookDeliveryCoordinator(
        app(DatabaseDeliveryStore::class),
        app(WebhookSecretResolver::class),
        app(StandardWebhookSigner::class),
        app(PinnedHttpClient::class),
        app(RetryPolicy::class),
        app(OutboundHttpLimits::class),
        timeoutSeconds: 15,
        connectTimeoutSeconds: 3,
        defaultRetryPreset: WebhookRetryPresetEnum::Standard,
        defaultMaxConcurrency: 10,
        maxResponseBytes: 1_048_576,
        exceptionHandler: app(ExceptionHandler::class),
        disablePolicy: app(EndpointDisablePolicy::class),
        events: app(Dispatcher::class),
        // One second per read: the run's start, each budget check, and an attempt's start and end.
        nanosecondClock: function () use (&$seconds, &$readings): int {
            return $readings === null ? $seconds++ * 1_000_000_000 : (int) array_shift($readings);
        },
    );
}

it('starts an attempt a nanosecond before the budget ends, and none at its end', function (): void {
    deliveryEndpoint(['max_concurrency' => 10]);
    foreach (range(1, 3) as $number) {
        coordinator()->dispatch(invoicePaidMessage("msg_{$number}"));
    }

    // The run's start, the first check, the attempt's start and end, the second check.
    expect(coordinatorOnAClockThatTicksPerRead([0, 999_999_999, 999_999_999, 999_999_999, 1_000_000_000])->work(50, 1))->toBe(1);
});

it('starts no new attempt once the time budget is spent', function (?int $maxSeconds, int $expectedAttempts): void {
    deliveryEndpoint(['max_concurrency' => 10]);
    foreach (range(1, 5) as $number) {
        coordinator()->dispatch(invoicePaidMessage("msg_{$number}"));
    }

    expect(coordinatorOnAClockThatTicksPerRead()->work(50, $maxSeconds))->toBe($expectedAttempts)
        ->and($this->transfer->requests)->toHaveCount($expectedAttempts);
})->with([
    'no budget' => [null, 5],
    'spent exactly at the fourth check' => [10, 3],
    'a second more' => [11, 4],
]);

it('refuses a time budget below one second', function (): void {
    coordinator()->work(50, 0);
})->throws(InvalidArgumentException::class, 'The work time budget must be at least 1 second.');

it('takes the time budget from the command', function (): void {
    foreach (['soon', '0'] as $refused) {
        $this->artisan('outbound:webhooks:work', ['--max-seconds' => $refused])
            ->expectsOutput('The --max-seconds option must be a positive integer.')
            ->assertFailed();
    }

    $this->artisan('outbound:webhooks:work', ['--max-seconds' => '1'])->assertSuccessful();
});
