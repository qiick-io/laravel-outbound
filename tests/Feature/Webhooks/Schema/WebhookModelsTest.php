<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Enums\WebhookAttemptStatusEnum;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Enums\WebhookDeliveryTriggerEnum;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Enums\WebhookSignatureSchemeEnum;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;
use Qiick\Outbound\Models\WebhookSubscription;

/*
 * These tests write every column, the package-managed ones included, so they
 * use forceFill(); what mass assignment accepts is SensitiveAttributesTest's.
 */

function createEndpointModel(array $attributes = []): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive', ...$attributes]);
    $endpoint->save();

    return $endpoint;
}

function createDeliveryModel(WebhookEndpoint $endpoint, array $attributes = []): WebhookDelivery
{
    $delivery = $endpoint->deliveries()->make()->usePayload('{"type":"invoice.paid"}')->forceFill([
        'event_id' => 'msg_1',
        'event_type' => 'invoice.paid',
        'occurred_at' => CarbonImmutable::parse('2026-11-22 10:00:00'),
        'payload_version' => '1',
        'max_attempts' => 10,
        'payload_expires_at' => CarbonImmutable::parse('2026-12-22 10:00:00'),
        ...$attributes,
    ]);
    $delivery->save();

    return $delivery;
}

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @param  TModel  $model
 * @param  array<string, mixed>  $attributes
 * @return TModel
 */
function forceCreateModel(Model $model, array $attributes): Model
{
    $model->forceFill($attributes)->save();

    return $model;
}

it('gives every model a UUID v7 public id as route key and keeps the bigint key incrementing', function (): void {
    $endpoint = createEndpointModel();
    $secondEndpoint = createEndpointModel();

    expect($endpoint->public_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($endpoint->getRouteKeyName())->toBe('public_id')
        ->and($endpoint->getIncrementing())->toBeTrue()
        ->and($endpoint->getKeyType())->toBe('int')
        ->and($secondEndpoint->id)->toBe($endpoint->id + 1);
});

it('reads a fresh endpoint like a stored row and casts every endpoint attribute', function (): void {
    $fresh = new WebhookEndpoint;

    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->consecutive_failures)->toBe(0)
        ->and($fresh->rate_window_attempts)->toBe(0)
        ->and($fresh->payload_version)->toBe('1');

    $userId = (int) DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);
    $endpoint = createEndpointModel([
        'is_active' => false,
        'disabled_at' => '2026-11-22 10:00:00',
        'disabled_reason' => WebhookEndpointDisabledReasonEnum::GoneHttp410,
        'failing_since' => '2026-11-20 10:00:00',
        'consecutive_failures' => '11',
        'rate_window_attempts' => 7,
        'rate_window_started_at' => '2026-11-22 10:00:00',
        'throttled_at' => '2026-11-22 10:00:30',
        'last_success_at' => '2026-11-19 10:00:00',
        'last_failure_at' => '2026-11-22 09:00:00',
        'https_only' => 1,
        'allow_private_hosts' => 0,
        'timeout_seconds' => '20',
        'max_concurrency' => '3',
        'rate_limit_per_minute' => '60',
        'max_attempts' => '5',
        'auto_disable' => 0,
        'verified_at' => '2026-11-18 10:00:00',
        'headers' => ['x-tenant' => 'acme'],
        'metadata' => ['source' => 'test'],
        'created_by' => (string) $userId,
    ]);
    $endpoint = WebhookEndpoint::query()->findOrFail($endpoint->id);
    $endpoint->delete();
    $endpoint = WebhookEndpoint::withTrashed()->findOrFail($endpoint->id);

    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($endpoint->disabled_reason)->toBe(WebhookEndpointDisabledReasonEnum::GoneHttp410)
        ->and($endpoint->failing_since)->toBeInstanceOf(CarbonImmutable::class)
        ->and($endpoint->consecutive_failures)->toBe(11)
        ->and($endpoint->rate_window_attempts)->toBe(7)
        ->and($endpoint->rate_window_started_at?->toDateTimeString())->toBe('2026-11-22 10:00:00')
        ->and($endpoint->throttled_at?->toDateTimeString())->toBe('2026-11-22 10:00:30')
        ->and($endpoint->last_success_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($endpoint->last_failure_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($endpoint->https_only)->toBeTrue()
        ->and($endpoint->allow_private_hosts)->toBeFalse()
        ->and($endpoint->timeout_seconds)->toBe(20)
        ->and($endpoint->max_concurrency)->toBe(3)
        ->and($endpoint->rate_limit_per_minute)->toBe(60)
        ->and($endpoint->max_attempts)->toBe(5)
        ->and($endpoint->auto_disable)->toBeFalse()
        ->and($endpoint->verified_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($endpoint->headers)->toBe(['x-tenant' => 'acme'])
        ->and($endpoint->metadata)->toBe(['source' => 'test'])
        ->and($endpoint->created_by)->toBe($userId)
        ->and($endpoint->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($endpoint->updated_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($endpoint->deleted_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(WebhookEndpoint::query()->find($endpoint->id))->toBeNull();
});

it('lets a product subclass override a column default and keep the others', function (): void {
    $productEndpoint = new class extends WebhookEndpoint
    {
        protected $attributes = ['payload_version' => '2'];
    };

    expect($productEndpoint->payload_version)->toBe('2')
        ->and($productEndpoint->is_active)->toBeTrue();
});

it('never serialises bigint ids, user ids, secrets or bodies', function (): void {
    $userId = (int) DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);
    $endpoint = createEndpointModel(['created_by' => $userId]);
    $secret = forceCreateModel($endpoint->secrets()->make(), ['secret' => 'whsec_c2VjcmV0']);
    $subscription = $endpoint->subscriptions()->create(['event_type' => '*']);
    $delivery = createDeliveryModel($endpoint, ['next_attempt_triggered_by' => $userId]);
    $attempt = forceCreateModel($delivery->attempts()->make(), [
        'attempt' => 1,
        'status' => WebhookAttemptStatusEnum::Failed,
        'webhook_timestamp' => 1_763_800_000,
        'signature_schemes' => 'v1',
        'triggered_by' => $userId,
        'attempted_at' => CarbonImmutable::now(),
    ]);

    expect(array_keys($endpoint->toArray()))->not->toContain('id')->not->toContain('created_by')
        ->and(array_keys($secret->toArray()))->not->toContain('id')->not->toContain('endpoint_id')->not->toContain('secret')
        ->and(array_keys($subscription->toArray()))->not->toContain('id')->not->toContain('endpoint_id')
        ->and(array_keys($delivery->toArray()))->not->toContain('id')->not->toContain('endpoint_id')->not->toContain('payload')->not->toContain('payload_hash')->not->toContain('next_attempt_triggered_by')
        ->and(array_keys($attempt->toArray()))->not->toContain('id')->not->toContain('delivery_id')->not->toContain('triggered_by')
        ->and($endpoint->toArray())->toHaveKey('public_id');
});

it('encrypts the secret at rest and casts every secret attribute', function (): void {
    $endpoint = createEndpointModel();
    $fresh = new WebhookEndpointSecret;

    expect($fresh->scheme)->toBe(WebhookSignatureSchemeEnum::V1)
        ->and($fresh->is_legacy_raw)->toBeFalse()
        ->and($fresh->is_primary)->toBeFalse();

    $secret = forceCreateModel($endpoint->secrets()->make(), [
        'secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
        'scheme' => 'v1',
        'is_legacy_raw' => 1,
        'is_primary' => 1,
        'expires_at' => '2026-11-23 10:00:00',
        'revoked_at' => '2026-11-22 10:00:00',
        'metadata' => ['rotated_by' => 'test'],
    ]);
    $secret = WebhookEndpointSecret::query()->findOrFail($secret->id);
    $storedSecret = (string) DB::table('webhook_endpoint_secrets')->value('secret');

    expect($storedSecret)->not->toContain('whsec_')
        ->and(decrypt($storedSecret, false))->toBe('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw')
        ->and($secret->secret)->toBe('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw')
        ->and($secret->endpoint_id)->toBe($endpoint->id)
        ->and($secret->scheme)->toBe(WebhookSignatureSchemeEnum::V1)
        ->and($secret->is_legacy_raw)->toBeTrue()
        ->and($secret->is_primary)->toBeTrue()
        ->and($secret->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($secret->revoked_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($secret->metadata)->toBe(['rotated_by' => 'test'])
        ->and($secret->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($secret->updated_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($secret->endpoint->is($endpoint))->toBeTrue()
        ->and($endpoint->secrets()->sole()->is($secret))->toBeTrue();
});

it('casts every subscription attribute and links it to its endpoint', function (): void {
    $endpoint = createEndpointModel();
    $subscription = forceCreateModel($endpoint->subscriptions()->make(), [
        'event_type' => WebhookSubscription::ALL_EVENT_TYPES,
        'filter' => ['amount' => ['gt' => 0]],
    ]);
    $subscription = WebhookSubscription::query()->findOrFail($subscription->id);

    expect($subscription->event_type)->toBe('*')
        ->and($subscription->endpoint_id)->toBe($endpoint->id)
        ->and($subscription->filter)->toBe(['amount' => ['gt' => 0]])
        ->and($subscription->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($subscription->updated_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($subscription->endpoint->is($endpoint))->toBeTrue()
        ->and($endpoint->subscriptions()->sole()->is($subscription))->toBeTrue();
});

it('encrypts the body at rest and casts every delivery attempt', function (): void {
    $fresh = new WebhookDelivery;

    expect($fresh->status)->toBe(DeliveryStatus::Pending)
        ->and($fresh->attempts_count)->toBe(0)
        ->and($fresh->redelivery_requests)->toBe(0)
        ->and($fresh->trigger)->toBe(WebhookDeliveryTriggerEnum::Event);

    $endpoint = createEndpointModel();
    $delivery = createDeliveryModel($endpoint, [
        'status' => 'exhausted',
        'attempts_count' => '3',
        'max_attempts' => '10',
        'next_attempt_at' => '2026-11-23 10:00:00',
        'claimed_until' => '2026-11-22 10:06:30',
        'exhausted_reason' => 'retry_after_exceeds_cap',
        'last_attempt_at' => '2026-11-22 10:05:00',
        'delivered_at' => '2026-11-22 10:06:00',
        'last_http_status' => '503',
        'payload_bytes' => '23',
        'payload_expires_at' => '2026-12-22 10:00:00',
        'trigger' => 'test',
        'metadata' => ['note' => 'x'],
    ]);
    $endpoint->delete();
    $delivery = WebhookDelivery::query()->findOrFail($delivery->id);
    $storedPayload = (string) DB::table('webhook_deliveries')->value('payload');

    expect($storedPayload)->not->toContain('invoice.paid')
        ->and(decrypt($storedPayload, false))->toBe('{"type":"invoice.paid"}')
        ->and($delivery->payload)->toBe('{"type":"invoice.paid"}')
        ->and($delivery->endpoint_id)->toBe($endpoint->id)
        ->and($delivery->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->attempts_count)->toBe(3)
        ->and($delivery->max_attempts)->toBe(10)
        ->and($delivery->next_attempt_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->claimed_until)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->exhausted_reason)->toBe(WebhookExhaustedReasonEnum::RetryAfterExceedsCap)
        ->and($delivery->last_attempt_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->delivered_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->last_http_status)->toBe(503)
        ->and($delivery->payload_bytes)->toBe(23)
        ->and($delivery->payload_expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->trigger)->toBe(WebhookDeliveryTriggerEnum::Test)
        ->and($delivery->metadata)->toBe(['note' => 'x'])
        ->and($delivery->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->updated_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($delivery->endpoint->is($endpoint))->toBeTrue();
});

it('appends attempts with milliseconds, no updated_at, and every attribute cast', function (): void {
    $fresh = new WebhookDeliveryAttempt;

    expect($fresh->trigger)->toBe(WebhookAttemptTriggerEnum::Schedule)
        ->and(WebhookDeliveryAttempt::UPDATED_AT)->toBeNull();

    $userId = (int) DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);
    $delivery = createDeliveryModel(createEndpointModel());
    $attempt = forceCreateModel($delivery->attempts()->make(), [
        'attempt' => '2',
        'trigger' => 'manual',
        'triggered_by' => (string) $userId,
        'status' => 'delivered',
        'webhook_timestamp' => '1763800000',
        'signature_schemes' => 'v1',
        'secret_public_ids' => ['0193-a', '0193-b'],
        'http_status' => '204',
        'retry_after_seconds' => '120',
        'response_headers' => ['content-type' => 'text/plain'],
        'response_bytes' => '0',
        'duration_ms' => '87',
        'attempted_at' => CarbonImmutable::parse('2026-11-22 10:00:00.456'),
        'next_attempt_at' => '2026-11-22 11:00:00',
        'metadata' => ['note' => 'x'],
    ]);
    $attempt = WebhookDeliveryAttempt::query()->findOrFail($attempt->id);

    expect(Schema::hasColumn('webhook_delivery_attempts', 'updated_at'))->toBeFalse()
        ->and($attempt->delivery_id)->toBe($delivery->id)
        ->and($attempt->attempt)->toBe(2)
        ->and($attempt->trigger)->toBe(WebhookAttemptTriggerEnum::Manual)
        ->and($attempt->triggered_by)->toBe($userId)
        ->and($attempt->status)->toBe(WebhookAttemptStatusEnum::Delivered)
        ->and($attempt->webhook_timestamp)->toBe(1_763_800_000)
        ->and($attempt->secret_public_ids)->toBe(['0193-a', '0193-b'])
        ->and($attempt->http_status)->toBe(204)
        ->and($attempt->retry_after_seconds)->toBe(120)
        ->and($attempt->response_headers)->toBe(['content-type' => 'text/plain'])
        ->and($attempt->response_bytes)->toBe(0)
        ->and($attempt->duration_ms)->toBe(87)
        ->and($attempt->attempted_at->format('H:i:s.v'))->toBe('10:00:00.456')
        ->and($attempt->next_attempt_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attempt->metadata)->toBe(['note' => 'x'])
        ->and($attempt->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attempt->delivery->is($delivery))->toBeTrue()
        ->and($delivery->attempts()->sole()->is($attempt))->toBeTrue();
});

it('reads an attempt status only from the three attempt outcomes', function (string $deliveryOnlyStatus): void {
    $delivery = createDeliveryModel(createEndpointModel());
    $attemptId = forceCreateModel($delivery->attempts()->make(), [
        'attempt' => 1,
        'status' => 'failed',
        'webhook_timestamp' => 1_763_800_000,
        'signature_schemes' => 'v1',
        'attempted_at' => CarbonImmutable::now(),
    ])->id;
    DB::table('webhook_delivery_attempts')->where('id', $attemptId)->update(['status' => $deliveryOnlyStatus]);

    expect(array_map(static fn (WebhookAttemptStatusEnum $status): string => $status->value, WebhookAttemptStatusEnum::cases()))
        ->toBe(['delivered', 'failed', 'refused'])
        ->and(fn () => WebhookDeliveryAttempt::query()->findOrFail($attemptId)->status)->toThrow(ValueError::class);
})->with(['pending', 'exhausted', 'cancelled']);
