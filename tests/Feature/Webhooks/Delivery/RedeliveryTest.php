<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Events\WebhookRedeliveryRequestedEvent;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Tests\Support\RecordingTransferHandler;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookMessage;

/*
 * Standards review R7 and M11: a manual or bulk redelivery reuses the
 * delivery's event_id as webhook-id ("Webhook metadata": the id stays the
 * same however many times a webhook is retried), with a fresh
 * webhook-timestamp and fresh signatures, as new attempts on the same row.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    config()->set('outbound.webhooks.retry_jitter_seconds', 0);
    Event::fake([WebhookRedeliveryRequestedEvent::class]);
    $this->transfer = new RecordingTransferHandler;
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['hooks.example.com' => ['93.184.216.34'], 'other.example.com' => ['93.184.216.35']])),
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
function redeliveryEndpoint(array $attributes = []): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive', 'max_concurrency' => 10, ...$attributes]);
    $endpoint->save();
    $endpoint->subscriptions()->create(['event_type' => 'invoice.paid']);
    app(SecretRotator::class)->issue($endpoint);

    return $endpoint;
}

function sentDelivery(WebhookEndpoint $endpoint, string $messageId = 'msg_1'): WebhookDelivery
{
    app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage($messageId, 'invoice.paid', new DateTimeImmutable('2026-11-23 09:00:00'), ['n' => 1]));

    return WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->where('event_id', $messageId)->sole();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function settledDelivery(WebhookEndpoint $endpoint, string $messageId, array $attributes): WebhookDelivery
{
    $delivery = sentDelivery($endpoint, $messageId);
    $delivery->forceFill(['next_attempt_at' => null, 'attempts_count' => 10, ...$attributes])->save();

    return $delivery->refresh();
}

function redeliveryUserId(): int
{
    return (int) DB::table('users')->insertGetId(['name' => 'Operator', 'email' => 'op-'.bin2hex(random_bytes(4)).'@example.com', 'password' => 'x']);
}

it('redelivers an exhausted delivery with the same webhook-id, a fresh timestamp and its own attempt', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted', 'exhausted_reason' => 'max_attempts', 'max_attempts' => 10]);
    $operatorId = redeliveryUserId();
    CarbonImmutable::setTestNow('2026-11-25 08:00:00');

    expect(app(WebhookDeliveryCoordinator::class)->redeliver($delivery, $operatorId))->toBeTrue()
        ->and(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(1);

    $request = $this->transfer->requests[0];
    $attempt = WebhookDeliveryAttempt::query()->sole();
    $delivery->refresh();

    expect($request->getHeaderLine('webhook-id'))->toBe('msg_1')
        ->and($request->getHeaderLine('webhook-timestamp'))->toBe((string) CarbonImmutable::parse('2026-11-25 08:00:00', 'UTC')->getTimestamp())
        ->and($attempt->attempt)->toBe(11)
        ->and($attempt->trigger)->toBe(WebhookAttemptTriggerEnum::Manual)
        ->and($attempt->triggered_by)->toBe($operatorId)
        ->and($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->exhausted_reason)->toBeNull()
        ->and($delivery->next_attempt_trigger)->toBeNull()
        ->and($delivery->next_attempt_triggered_by)->toBeNull();
});

it('gives a settled delivery exactly one more attempt: a failed redelivery is exhausted again', function (string $status): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => $status, 'max_attempts' => 10]);
    $this->transfer->respond(500);

    app(WebhookDeliveryCoordinator::class)->redeliver($delivery);
    app(WebhookDeliveryCoordinator::class)->work(50);
    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->exhausted_reason)->toBe(WebhookExhaustedReasonEnum::MaxAttempts)
        ->and($delivery->max_attempts)->toBe(11)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and(WebhookDeliveryAttempt::query()->sole()->trigger)->toBe(WebhookAttemptTriggerEnum::Manual)
        ->and(WebhookDeliveryAttempt::query()->sole()->triggered_by)->toBeNull();
})->with(['exhausted', 'delivered', 'refused']);

it('sends a pending delivery now and keeps its schedule when that attempt fails', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = sentDelivery($endpoint);
    $delivery->forceFill(['attempts_count' => 2, 'next_attempt_at' => '2026-11-23 12:00:00'])->save();
    $this->transfer->respond(500);

    app(WebhookDeliveryCoordinator::class)->redeliver($delivery);
    app(WebhookDeliveryCoordinator::class)->work(50);
    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->max_attempts)->toBe(10)
        ->and($delivery->attempts_count)->toBe(3)
        // The third attempt failed: the schedule's third delay, 30 min.
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:30:00')
        ->and(WebhookDeliveryAttempt::query()->sole()->trigger)->toBe(WebhookAttemptTriggerEnum::Manual);
});

it('records later scheduled attempts as scheduled again', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = sentDelivery($endpoint);
    $this->transfer->respond(500)->respond(200);

    app(WebhookDeliveryCoordinator::class)->redeliver($delivery);
    app(WebhookDeliveryCoordinator::class)->work(50);
    CarbonImmutable::setTestNow('2026-11-23 10:00:05');
    app(WebhookDeliveryCoordinator::class)->work(50);

    expect(WebhookDeliveryAttempt::query()->orderBy('attempt')->pluck('trigger')->all())
        ->toBe([WebhookAttemptTriggerEnum::Manual, WebhookAttemptTriggerEnum::Schedule]);
});

it('refuses to redeliver to a disabled endpoint or a body that has been pruned', function (array $endpointAttributes, array $deliveryAttributes, string $message): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted', ...$deliveryAttributes]);
    $endpoint->forceFill($endpointAttributes)->save();

    expect(fn () => app(WebhookDeliveryCoordinator::class)->redeliver($delivery->refresh()))->toThrow(WebhookException::class, $message)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Exhausted);
    Event::assertNotDispatched(WebhookRedeliveryRequestedEvent::class);
})->with([
    'disabled endpoint' => [['is_active' => false], [], 'Webhook endpoint is disabled; enable it before redelivering.'],
    'pruned body' => [[], ['payload' => null], 'Webhook delivery body has been pruned; it cannot be redelivered.'],
]);

it('refuses to redeliver to a deleted endpoint', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted']);
    $endpoint->delete();

    app(WebhookDeliveryCoordinator::class)->redeliver($delivery);
})->throws(WebhookException::class, 'Webhook endpoint is disabled; enable it before redelivering.');

it('announces a single redelivery', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted']);
    $operatorId = redeliveryUserId();

    app(WebhookDeliveryCoordinator::class)->redeliver($delivery, $operatorId);

    Event::assertDispatched(WebhookRedeliveryRequestedEvent::class, fn (WebhookRedeliveryRequestedEvent $event): bool => $event->endpointPublicId === $endpoint->public_id
        && $event->deliveryCount === 1
        && $event->trigger === WebhookAttemptTriggerEnum::Manual
        && $event->triggeredBy === $operatorId);
});

it('redelivers every delivery of an endpoint in a time range with the given statuses', function (): void {
    $endpoint = redeliveryEndpoint();
    $other = redeliveryEndpoint(['url' => 'https://other.example.com/receive']);
    $inRange = settledDelivery($endpoint, 'msg_in', ['status' => 'exhausted', 'created_at' => '2026-11-22 12:00:00']);
    $delivered = settledDelivery($endpoint, 'msg_ok', ['status' => 'delivered', 'created_at' => '2026-11-22 12:00:00']);
    $tooEarly = settledDelivery($endpoint, 'msg_early', ['status' => 'exhausted', 'created_at' => '2026-11-21 23:59:59']);
    $atTheEnd = settledDelivery($endpoint, 'msg_end', ['status' => 'exhausted', 'created_at' => '2026-11-23 00:00:00']);
    $pruned = settledDelivery($endpoint, 'msg_pruned', ['status' => 'exhausted', 'created_at' => '2026-11-22 12:00:00', 'payload' => null]);
    $otherEndpoint = WebhookDelivery::query()->where('endpoint_id', $other->id)->where('event_id', 'msg_in')->sole();
    $otherEndpoint->forceFill(['status' => 'exhausted', 'next_attempt_at' => null])->save();
    $operatorId = redeliveryUserId();

    $count = app(WebhookDeliveryCoordinator::class)->redeliverRange(
        $endpoint,
        CarbonImmutable::parse('2026-11-22 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC'),
        [DeliveryStatus::Exhausted],
        $operatorId,
    );
    app(WebhookDeliveryCoordinator::class)->work(50);

    expect($count)->toBe(1)
        ->and($inRange->refresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($inRange->attempts()->sole()->trigger)->toBe(WebhookAttemptTriggerEnum::Bulk)
        ->and($inRange->attempts()->sole()->triggered_by)->toBe($operatorId)
        ->and($delivered->refresh()->attempts()->count())->toBe(0)
        ->and($tooEarly->refresh()->status)->toBe(DeliveryStatus::Exhausted)
        ->and($atTheEnd->refresh()->status)->toBe(DeliveryStatus::Exhausted)
        ->and($pruned->refresh()->status)->toBe(DeliveryStatus::Exhausted)
        ->and($otherEndpoint->refresh()->status)->toBe(DeliveryStatus::Exhausted)
        // The other endpoint's own pending deliveries go out too; only one to this endpoint.
        ->and(array_filter($this->transfer->requests, static fn ($request): bool => $request->getUri()->getHost() === 'hooks.example.com'))->toHaveCount(1);

    Event::assertDispatched(WebhookRedeliveryRequestedEvent::class, fn (WebhookRedeliveryRequestedEvent $event): bool => $event->deliveryCount === 1
        && $event->trigger === WebhookAttemptTriggerEnum::Bulk
        && $event->triggeredBy === $operatorId);
});

it('redelivers pending deliveries in a range now, keeping their schedule', function (): void {
    $endpoint = redeliveryEndpoint();
    $pending = sentDelivery($endpoint, 'msg_pending');
    $pending->forceFill(['attempts_count' => 3, 'next_attempt_at' => '2026-11-23 15:00:00'])->save();

    $count = app(WebhookDeliveryCoordinator::class)->redeliverRange(
        $endpoint,
        CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-24 00:00:00', 'UTC'),
        [DeliveryStatus::Pending, DeliveryStatus::Exhausted],
    );
    $pending->refresh();

    expect($count)->toBe(1)
        ->and($pending->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($pending->max_attempts)->toBe(10)
        ->and($pending->next_attempt_trigger)->toBe(WebhookAttemptTriggerEnum::Bulk);
});

it('refuses a bulk redelivery to a disabled endpoint, an empty range or no status', function (array $endpointAttributes, string $from, string $until, array $statuses, string $message): void {
    $endpoint = redeliveryEndpoint();
    $endpoint->forceFill($endpointAttributes)->save();

    expect(fn () => app(WebhookDeliveryCoordinator::class)->redeliverRange($endpoint, CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($until, 'UTC'), $statuses))
        ->toThrow(WebhookException::class, $message);
})->with([
    'disabled endpoint' => [['is_active' => false], '2026-11-22', '2026-11-23', [DeliveryStatus::Exhausted], 'Webhook endpoint is disabled; enable it before redelivering.'],
    'empty range' => [[], '2026-11-23', '2026-11-23', [DeliveryStatus::Exhausted], 'The redelivery range must end after it starts.'],
    'no status' => [[], '2026-11-22', '2026-11-23', [], 'A bulk redelivery needs at least one status.'],
]);

it('announces nothing when a bulk redelivery matches nothing', function (): void {
    $endpoint = redeliveryEndpoint();

    expect(app(WebhookDeliveryCoordinator::class)->redeliverRange($endpoint, CarbonImmutable::parse('2026-11-22', 'UTC'), CarbonImmutable::parse('2026-11-23', 'UTC'), [DeliveryStatus::Exhausted]))->toBe(0);
    Event::assertNotDispatched(WebhookRedeliveryRequestedEvent::class);
});

it('reports a delivery deleted before the redelivery as not scheduled', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted']);
    WebhookDelivery::query()->whereKey($delivery->id)->delete();

    expect(app(WebhookDeliveryCoordinator::class)->redeliver($delivery))->toBeFalse();
    Event::assertNotDispatched(WebhookRedeliveryRequestedEvent::class);
});

/*
 * Review of #35 (docs-versus-code check): the edge cases below.
 */

it('refuses a bulk redelivery to a deleted endpoint and a range that ends before it starts', function (bool $isDeleted, string $from, string $until, string $message): void {
    $endpoint = redeliveryEndpoint();

    if ($isDeleted) {
        $endpoint->delete();
    }

    expect(fn () => app(WebhookDeliveryCoordinator::class)->redeliverRange($endpoint, CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($until, 'UTC'), [DeliveryStatus::Exhausted]))
        ->toThrow(WebhookException::class, $message);
})->with([
    'deleted endpoint' => [true, '2026-11-22', '2026-11-23', 'Webhook endpoint is disabled; enable it before redelivering.'],
    'inverted range' => [false, '2026-11-23', '2026-11-22', 'The redelivery range must end after it starts.'],
]);

it('sends a delivery asked for twice before the worker runs only once, for the latest request', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted', 'max_attempts' => 10]);
    $firstOperatorId = redeliveryUserId();
    $secondOperatorId = redeliveryUserId();

    app(WebhookDeliveryCoordinator::class)->redeliver($delivery, $firstOperatorId);
    app(WebhookDeliveryCoordinator::class)->redeliver($delivery->refresh(), $secondOperatorId);

    expect($delivery->refresh()->max_attempts)->toBe(11)
        ->and(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(1)
        ->and(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(0)
        ->and(WebhookDeliveryAttempt::query()->sole()->triggered_by)->toBe($secondOperatorId);
});

it('honours a redelivery asked for while the delivery is being sent', function (int $status): void {
    $endpoint = redeliveryEndpoint();
    $delivery = sentDelivery($endpoint);
    $operatorId = redeliveryUserId();
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['hooks.example.com' => ['93.184.216.34']])),
        $this->app->make(OutboundHttpLimits::class),
        static fn (): float => 1_000.0,
        function ($request, array $options) use ($delivery, $operatorId, $status) {
            // A user asks for it again while this attempt is on the wire.
            if (count($this->transfer->requests) === 0) {
                app(WebhookDeliveryCoordinator::class)->redeliver($delivery->refresh(), $operatorId);
                $this->transfer->respond($status);
            }

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    app(WebhookDeliveryCoordinator::class)->work(50);
    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($delivery->next_attempt_trigger)->toBe(WebhookAttemptTriggerEnum::Manual)
        ->and($delivery->next_attempt_triggered_by)->toBe($operatorId)
        ->and(WebhookDeliveryAttempt::query()->sole()->trigger)->toBe(WebhookAttemptTriggerEnum::Schedule);

    expect(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(1)
        ->and(WebhookDeliveryAttempt::query()->orderBy('attempt')->pluck('trigger')->all())->toBe([WebhookAttemptTriggerEnum::Schedule, WebhookAttemptTriggerEnum::Manual]);
})->with([
    'the in-flight attempt is delivered' => [200],
    'the in-flight attempt fails' => [500],
]);

it('keeps the time of the last delivery and clears the exhausted reason when redelivering', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivered = settledDelivery($endpoint, 'msg_delivered', ['status' => 'delivered', 'delivered_at' => '2026-11-22 10:00:00']);
    $exhausted = settledDelivery($endpoint, 'msg_exhausted', ['status' => 'exhausted', 'exhausted_reason' => 'gone']);

    app(WebhookDeliveryCoordinator::class)->redeliver($delivered);
    app(WebhookDeliveryCoordinator::class)->redeliver($exhausted);

    expect($delivered->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivered->delivered_at?->toDateTimeString())->toBe('2026-11-22 10:00:00')
        ->and($exhausted->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($exhausted->exhausted_reason)->toBeNull();

    $this->transfer->respond(200)->respond(500);
    app(WebhookDeliveryCoordinator::class)->work(50);

    expect($delivered->refresh()->delivered_at?->toDateTimeString())->toBe('2026-11-23 10:00:00');
});

it('forgets who asked for a redelivery when that user is deleted', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted']);
    $operatorId = redeliveryUserId();
    app(WebhookDeliveryCoordinator::class)->redeliver($delivery, $operatorId);

    DB::table('users')->where('id', $operatorId)->delete();

    expect($delivery->refresh()->next_attempt_triggered_by)->toBeNull()
        ->and($delivery->next_attempt_trigger)->toBe(WebhookAttemptTriggerEnum::Manual);
});

it('honours a second user\'s request made while the first user\'s redelivery is being sent', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted', 'max_attempts' => 10]);
    $firstOperatorId = redeliveryUserId();
    $secondOperatorId = redeliveryUserId();
    app(WebhookDeliveryCoordinator::class)->redeliver($delivery, $firstOperatorId);
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['hooks.example.com' => ['93.184.216.34']])),
        $this->app->make(OutboundHttpLimits::class),
        static fn (): float => 1_000.0,
        function ($request, array $options) use ($delivery, $secondOperatorId) {
            // Same trigger, another user: only the asker differs.
            if (count($this->transfer->requests) === 0) {
                app(WebhookDeliveryCoordinator::class)->redeliver($delivery->refresh(), $secondOperatorId);
            }

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    app(WebhookDeliveryCoordinator::class)->work(50);
    app(WebhookDeliveryCoordinator::class)->work(50);

    expect(WebhookDeliveryAttempt::query()->orderBy('attempt')->pluck('triggered_by')->all())->toBe([$firstOperatorId, $secondOperatorId]);
});

/*
 * Adversarial review of #35, B1: a request repeated by the same asker (or
 * with no user) while the delivery is being sent must not be lost.
 */
it('honours a repeated request by the same asker made while the delivery is being sent', function (?bool $withUser, string $api): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted', 'max_attempts' => 10, 'created_at' => '2026-11-23 08:00:00']);
    $operatorId = $withUser ? redeliveryUserId() : null;
    $ask = function () use ($api, $delivery, $endpoint, $operatorId): void {
        if ($api === 'single') {
            app(WebhookDeliveryCoordinator::class)->redeliver($delivery->refresh(), $operatorId);
        } else {
            app(WebhookDeliveryCoordinator::class)->redeliverRange($endpoint, CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC'), CarbonImmutable::parse('2026-11-24 00:00:00', 'UTC'), [DeliveryStatus::Exhausted, DeliveryStatus::Pending], $operatorId);
        }
    };
    $ask();
    $this->transfer->respond(500);
    $this->app->instance(PinnedHttpClient::class, new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['hooks.example.com' => ['93.184.216.34']])),
        $this->app->make(OutboundHttpLimits::class),
        static fn (): float => 1_000.0,
        function ($request, array $options) use ($ask) {
            // The same asker clicks again while the first redelivery is on the wire.
            if (count($this->transfer->requests) === 0) {
                $ask();
            }

            return ($this->transfer)($request, $options);
        },
    ));
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    app(WebhookDeliveryCoordinator::class)->work(50);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and(app(WebhookDeliveryCoordinator::class)->work(50))->toBe(1)
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(2);
})->with([
    'same user, single' => [true, 'single'],
    'no user, single' => [false, 'single'],
    'same user, bulk' => [true, 'bulk'],
    'no user, bulk' => [false, 'bulk'],
]);

it('reports a delivery pruned after it was loaded as not scheduled', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted']);
    WebhookDelivery::query()->whereKey($delivery->id)->update(['payload' => null]);

    expect(app(WebhookDeliveryCoordinator::class)->redeliver($delivery))->toBeFalse()
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Exhausted);
    Event::assertNotDispatched(WebhookRedeliveryRequestedEvent::class);
});

it('treats a cancelled delivery as withdrawn for good', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'cancelled', 'created_at' => '2026-11-22 12:00:00']);

    expect(fn () => app(WebhookDeliveryCoordinator::class)->redeliver($delivery))->toThrow(WebhookException::class, 'A cancelled delivery was withdrawn and cannot be redelivered.')
        ->and(fn () => app(WebhookDeliveryCoordinator::class)->redeliverRange($endpoint, CarbonImmutable::parse('2026-11-22', 'UTC'), CarbonImmutable::parse('2026-11-23', 'UTC'), [DeliveryStatus::Cancelled]))->toThrow(WebhookException::class, 'A cancelled delivery was withdrawn and cannot be redelivered.')
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Cancelled);
});

it('says in the audit event what was redelivered', function (): void {
    $endpoint = redeliveryEndpoint();
    $delivery = settledDelivery($endpoint, 'msg_1', ['status' => 'exhausted', 'created_at' => '2026-11-22 12:00:00']);
    $from = CarbonImmutable::parse('2026-11-22', 'UTC');
    $until = CarbonImmutable::parse('2026-11-23', 'UTC');

    app(WebhookDeliveryCoordinator::class)->redeliver($delivery);
    app(WebhookDeliveryCoordinator::class)->redeliverRange($endpoint, $from, $until, [DeliveryStatus::Exhausted, DeliveryStatus::Pending]);

    Event::assertDispatched(WebhookRedeliveryRequestedEvent::class, fn (WebhookRedeliveryRequestedEvent $event): bool => $event->trigger === WebhookAttemptTriggerEnum::Manual
        && $event->deliveryPublicId === $delivery->public_id
        && $event->from === null && $event->until === null && $event->statuses === []);
    Event::assertDispatched(WebhookRedeliveryRequestedEvent::class, fn (WebhookRedeliveryRequestedEvent $event): bool => $event->trigger === WebhookAttemptTriggerEnum::Bulk
        && $event->deliveryPublicId === null
        && $event->from?->equalTo($from) === true && $event->until?->equalTo($until) === true
        && $event->statuses === [DeliveryStatus::Exhausted, DeliveryStatus::Pending]);
});
