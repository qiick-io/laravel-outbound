<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\EndpointDisablePolicy;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
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
 * Standards review R8 and M10 (the maintainer's decision Q2 of 2026-11-22): 410 Gone disables
 * an endpoint at once; otherwise it is disabled after 5 days without a
 * success and at least 10 consecutive failed attempts. The owner hears of it
 * through WebhookEndpointDisabledEvent.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    config()->set('outbound.webhooks.retry_jitter_seconds', 0);
    Event::fake([WebhookEndpointDisabledEvent::class]);
    $this->transfer = new RecordingTransferHandler;
    $this->app->instance(PinnedHttpClient::class, PinnedHttpClient::forTesting(
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
function failingEndpoint(array $attributes = []): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive', ...$attributes]);
    $endpoint->save();
    $endpoint->subscriptions()->create(['event_type' => 'invoice.paid']);
    app(SecretRotator::class)->issue($endpoint);

    return $endpoint;
}

function sendOneAttempt(string $messageId = 'msg_1'): void
{
    app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage($messageId, 'invoice.paid', new DateTimeImmutable('2026-11-23 09:00:00'), []));
    app(WebhookDeliveryCoordinator::class)->work(50);
}

it('disables the endpoint at once on 410 Gone and fires the event once', function (): void {
    $endpoint = failingEndpoint();
    $this->transfer->respond(410);

    sendOneAttempt();
    $endpoint->refresh();

    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_reason)->toBe(WebhookEndpointDisabledReasonEnum::GoneHttp410)
        ->and($endpoint->disabled_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Exhausted);

    Event::assertDispatchedTimes(WebhookEndpointDisabledEvent::class, 1);
    Event::assertDispatched(WebhookEndpointDisabledEvent::class, fn (WebhookEndpointDisabledEvent $event): bool => $event->endpointPublicId === $endpoint->public_id
        && $event->reason === WebhookEndpointDisabledReasonEnum::GoneHttp410
        && $event->disabledAt->toDateTimeString() === '2026-11-23 10:00:00');
});

it('disables after 5 days without a success and 10 consecutive failures', function (int $failuresBefore, string $failingSince, bool $isDisabled): void {
    $endpoint = failingEndpoint(['consecutive_failures' => $failuresBefore, 'failing_since' => $failingSince]);
    $this->transfer->respond(500);

    sendOneAttempt();
    $endpoint->refresh();

    expect($endpoint->is_active)->toBe(! $isDisabled)
        ->and($endpoint->disabled_reason)->toBe($isDisabled ? WebhookEndpointDisabledReasonEnum::AutoFailure : null);

    Event::assertDispatchedTimes(WebhookEndpointDisabledEvent::class, $isDisabled ? 1 : 0);
})->with([
    'tenth failure, failing for exactly 5 days' => [9, '2026-11-18 10:00:00', true],
    'twentieth failure, failing for a week' => [19, '2026-11-16 10:00:00', true],
    'ninth failure, failing for a week' => [8, '2026-11-16 10:00:00', false],
    'tenth failure, one second short of 5 days' => [9, '2026-11-18 10:00:01', false],
]);

it('starts the streak with the first failure, so a new endpoint is not disabled', function (): void {
    $endpoint = failingEndpoint();
    $this->transfer->respond(500);

    sendOneAttempt();

    expect($endpoint->refresh()->is_active)->toBeTrue();
    Event::assertNotDispatched(WebhookEndpointDisabledEvent::class);
});

it('never disables on a success', function (): void {
    $endpoint = failingEndpoint(['consecutive_failures' => 30, 'failing_since' => '2026-11-01 10:00:00']);

    sendOneAttempt();

    expect($endpoint->refresh()->is_active)->toBeTrue()
        ->and($endpoint->consecutive_failures)->toBe(0);
    Event::assertNotDispatched(WebhookEndpointDisabledEvent::class);
});

it('leaves the endpoint alone when auto-disable is off for it or in the config', function (?bool $endpointSetting, bool $configSetting, bool $isDisabled): void {
    config()->set('outbound.webhooks.auto_disable.enabled', $configSetting);
    $this->app->forgetInstance(EndpointDisablePolicy::class);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    $gone = failingEndpoint(['auto_disable' => $endpointSetting]);
    $streak = failingEndpoint(['auto_disable' => $endpointSetting, 'consecutive_failures' => 9, 'failing_since' => '2026-11-16 10:00:00']);
    $this->transfer->respond(410)->respond(500);

    sendOneAttempt();

    expect($gone->refresh()->is_active)->toBe(! $isDisabled)
        ->and($streak->refresh()->is_active)->toBe(! $isDisabled);
})->with([
    'endpoint off, config on' => [false, true, false],
    'endpoint inherits, config off' => [null, false, false],
    'endpoint on, config off' => [true, false, true],
    'endpoint inherits, config on' => [null, true, true],
]);

it('reads the streak thresholds from the config', function (): void {
    config()->set('outbound.webhooks.auto_disable.no_success_days', 1);
    config()->set('outbound.webhooks.auto_disable.min_consecutive_failures', 3);
    $this->app->forgetInstance(EndpointDisablePolicy::class);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    $endpoint = failingEndpoint(['consecutive_failures' => 2, 'failing_since' => '2026-11-22 10:00:00']);
    $this->transfer->respond(500);

    sendOneAttempt();

    expect($endpoint->refresh()->disabled_reason)->toBe(WebhookEndpointDisabledReasonEnum::AutoFailure);
});

it('does not decide on an attempt another worker already recorded', function (): void {
    // Already failing: a 410 the worker did record would disable it.
    $endpoint = failingEndpoint(['consecutive_failures' => 3, 'failing_since' => '2026-11-22 10:00:00']);
    app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage('msg_1', 'invoice.paid', new DateTimeImmutable('2026-11-23 09:00:00'), []));
    $this->app->instance(PinnedHttpClient::class, PinnedHttpClient::forTesting(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['hooks.example.com' => ['93.184.216.34']])),
        $this->app->make(OutboundHttpLimits::class),
        static fn (): float => 1_000.0,
        function ($request, array $options) {
            WebhookDelivery::query()->update(['attempts_count' => 1]);

            return ($this->transfer->respond(410))($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    app(WebhookDeliveryCoordinator::class)->work(50);

    expect($endpoint->refresh()->is_active)->toBeTrue();
    Event::assertNotDispatched(WebhookEndpointDisabledEvent::class);
});

it('stops sending the pending deliveries of a disabled endpoint, and resumes them once it is enabled again', function (): void {
    $endpoint = failingEndpoint(['max_concurrency' => 5]);
    $this->transfer->respond(410);
    app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage('msg_1', 'invoice.paid', new DateTimeImmutable('2026-11-23 09:00:00'), []));
    app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage('msg_2', 'invoice.paid', new DateTimeImmutable('2026-11-23 09:00:00'), []));
    // The first attempt answers 410; the second delivery waits for its turn.
    WebhookDelivery::query()->where('event_id', 'msg_2')->update(['next_attempt_at' => '2026-11-23 10:00:01']);

    app(WebhookDeliveryCoordinator::class)->work(50);
    CarbonImmutable::setTestNow('2026-11-23 10:00:05');

    expect(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(0)
        ->and(app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage('msg_3', 'invoice.paid', new DateTimeImmutable('2026-11-23 10:00:00'), [])))->toHaveCount(0)
        ->and(app(WebhookDeliveryCoordinator::class)->enableEndpoint($endpoint->refresh()))->toBeTrue();

    $endpoint->refresh();

    expect($endpoint->is_active)->toBeTrue()
        ->and($endpoint->disabled_at)->toBeNull()
        ->and($endpoint->disabled_reason)->toBeNull()
        ->and($endpoint->consecutive_failures)->toBe(0)
        ->and($endpoint->failing_since)->toBeNull()
        ->and(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(1)
        ->and(app(WebhookDeliveryCoordinator::class)->enableEndpoint($endpoint))->toBeFalse();
});

it('disables an endpoint by hand once, with the manual reason', function (): void {
    $endpoint = failingEndpoint();
    $coordinator = app(WebhookDeliveryCoordinator::class);

    expect($coordinator->disableEndpoint($endpoint))->toBeTrue()
        ->and($coordinator->disableEndpoint($endpoint))->toBeFalse()
        ->and($endpoint->refresh()->disabled_reason)->toBe(WebhookEndpointDisabledReasonEnum::Manual)
        ->and($endpoint->is_active)->toBeFalse();

    Event::assertDispatchedTimes(WebhookEndpointDisabledEvent::class, 1);
    Event::assertDispatched(WebhookEndpointDisabledEvent::class, fn (WebhookEndpointDisabledEvent $event): bool => $event->reason === WebhookEndpointDisabledReasonEnum::Manual);
});

/*
 * Review of #31 (2026-11-23).
 */

it('never lets input choose the owner or opt an endpoint out of auto-disable', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.com/receive',
        'created_by' => 1,
        'auto_disable' => false,
    ]);

    expect($endpoint->refresh()->created_by)->toBeNull()
        ->and($endpoint->auto_disable)->toBeNull();
});

it('does not disable when a success lands between the decision and the switch', function (WebhookEndpointDisabledReasonEnum $expectedReason, int $failuresBefore, int $status, array $meanwhile): void {
    $endpoint = failingEndpoint(['consecutive_failures' => $failuresBefore, 'failing_since' => '2026-11-16 10:00:00']);
    $this->transfer->respond($status);
    $isAttemptStored = false;
    $hasSucceededMeanwhile = false;
    DB::listen(function ($query) use (&$isAttemptStored, &$hasSucceededMeanwhile, $endpoint, $meanwhile): void {
        if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'webhook_delivery_attempts')) {
            $isAttemptStored = true;
        } elseif ($isAttemptStored && ! $hasSucceededMeanwhile && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'webhook_endpoints')) {
            // Another worker records a success right after this one read the streak.
            $hasSucceededMeanwhile = true;
            WebhookEndpoint::query()->whereKey($endpoint->id)->update($meanwhile);
        }
    });

    sendOneAttempt();

    expect($hasSucceededMeanwhile)->toBeTrue()
        ->and($endpoint->refresh()->is_active)->toBeTrue();
    Event::assertNotDispatched(WebhookEndpointDisabledEvent::class);
})->with([
    'failure streak, then a success' => [WebhookEndpointDisabledReasonEnum::AutoFailure, 9, 500, ['consecutive_failures' => 0, 'failing_since' => null]],
    'failure streak, then a success and new failures' => [WebhookEndpointDisabledReasonEnum::AutoFailure, 9, 500, ['consecutive_failures' => 12, 'failing_since' => '2026-11-23 09:00:00']],
    '410 Gone, then a success' => [WebhookEndpointDisabledReasonEnum::GoneHttp410, 0, 410, ['consecutive_failures' => 0, 'failing_since' => null]],
]);

it('refuses thresholds of zero or less when the application boots', function (string $key, int $value): void {
    $this->configBeforeBoot = ["outbound.webhooks.auto_disable.{$key}" => $value];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, "outbound.webhooks.auto_disable.{$key} must be at least 1.");

    $this->configBeforeBoot = [];
    $this->refreshApplication();
})->with([
    ['no_success_days', 0],
    ['no_success_days', -1],
    ['min_consecutive_failures', 0],
    ['min_consecutive_failures', -5],
]);

it('accepts thresholds of one', function (): void {
    expect(new EndpointDisablePolicy(true, 1, 1))->toBeInstanceOf(EndpointDisablePolicy::class);
});

it('does not count the package\'s own refusals towards the streak', function (): void {
    // Already past both thresholds: one more receiver failure would disable it.
    $endpoint = failingEndpoint(['consecutive_failures' => 10, 'failing_since' => '2026-11-16 10:00:00', 'url' => 'https://internal.example.com/hook']);

    sendOneAttempt();

    expect($endpoint->refresh()->is_active)->toBeTrue()
        ->and($endpoint->consecutive_failures)->toBe(10);
    Event::assertNotDispatched(WebhookEndpointDisabledEvent::class);
});

it('keeps the endpoint disabled and reports when a product listener throws', function (): void {
    // A real dispatcher: the fake from beforeEach runs no listener.
    $events = new Dispatcher($this->app);
    $events->listen(WebhookEndpointDisabledEvent::class, function (): void {
        throw new RuntimeException('The product listener failed.');
    });
    Event::swap($events);
    $exceptionHandler = new class implements ExceptionHandler
    {
        /** @var list<string> */
        public array $reported = [];

        public function report(Throwable $e): void
        {
            $this->reported[] = $e->getMessage();
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
    $this->app->instance(ExceptionHandler::class, $exceptionHandler);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    $endpoint = failingEndpoint();
    $this->transfer->respond(410);

    sendOneAttempt();

    expect($endpoint->refresh()->is_active)->toBeFalse()
        ->and($exceptionHandler->reported)->toBe(['The product listener failed.']);
});

it('does not disable on a failure count without a streak start', function (): void {
    $endpoint = (new WebhookEndpoint)->forceFill(['consecutive_failures' => 20, 'failing_since' => null]);

    expect((new EndpointDisablePolicy(true, 5, 10))->reasonToDisable($endpoint, 500, CarbonImmutable::now()))->toBeNull();
});

it('asks the switch for the full streak on a failure-streak disable, and for one failure on 410', function (): void {
    $policy = new EndpointDisablePolicy(true, 5, 10);
    $now = CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC');

    expect($policy->streakStillRequired(WebhookEndpointDisabledReasonEnum::AutoFailure, $now))->toEqual(['minConsecutiveFailures' => 10, 'failingSinceAtLatest' => CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC')])
        ->and($policy->streakStillRequired(WebhookEndpointDisabledReasonEnum::GoneHttp410, $now))->toBe(['minConsecutiveFailures' => 1, 'failingSinceAtLatest' => null]);
});
