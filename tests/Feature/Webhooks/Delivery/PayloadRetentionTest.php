<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Retry\RetryPolicy;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookMessage;

/*
 * Standards review R5 and M12 (the maintainer's decision Q3 of 2026-11-22): the encrypted body
 * is kept for 30 days, which covers the 75 h schedule plus a window for
 * manual redelivery; then only its SHA-256 hash and its length remain.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function retentionEndpoint(): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive']);
    $endpoint->save();
    $endpoint->subscriptions()->create(['event_type' => 'invoice.paid']);
    app(SecretRotator::class)->issue($endpoint);

    return $endpoint;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function retainedDelivery(string $messageId, array $attributes = []): WebhookDelivery
{
    app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage($messageId, 'invoice.paid', new DateTimeImmutable('2026-11-23 09:00:00'), ['n' => 1]));
    $delivery = WebhookDelivery::query()->where('event_id', $messageId)->sole();
    $delivery->forceFill($attributes)->save();

    return $delivery->refresh();
}

it('keeps a dispatched body until 30 days after dispatch, or the configured days', function (?int $retentionDays, string $expiresAt): void {
    if ($retentionDays !== null) {
        config()->set('outbound.webhooks.payload_retention_days', $retentionDays);
        $this->app->forgetInstance(DatabaseDeliveryStore::class);
        $this->app->forgetInstance(WebhookDeliveryCoordinator::class);
    }

    retentionEndpoint();

    expect(retainedDelivery('msg_1')->payload_expires_at?->toDateTimeString())->toBe($expiresAt);
})->with([
    'default' => [null, '2026-12-23 10:00:00'],
    // The default plan (10 attempts, Retry-After up to 24 h honoured) can last
    // 9 days and 1 h: longer than 7 days, so the body stays until then.
    'configured, shorter than the retry plan' => [7, '2026-12-02 11:04:30'],
    'configured, longer than the retry plan' => [12, '2026-12-05 10:00:00'],
]);

it('clears expired bodies and keeps their hash and length', function (): void {
    retentionEndpoint();
    $expired = retainedDelivery('msg_expired', ['status' => 'delivered', 'next_attempt_at' => null, 'payload_expires_at' => '2026-11-23 09:59:59']);
    $atTheLimit = retainedDelivery('msg_limit', ['status' => 'exhausted', 'next_attempt_at' => null, 'payload_expires_at' => '2026-11-23 10:00:00']);
    $kept = retainedDelivery('msg_kept', ['status' => 'delivered', 'next_attempt_at' => null, 'payload_expires_at' => '2026-11-23 10:00:01']);
    $hash = $expired->payload_hash;

    $this->artisan('outbound:webhooks:prune')
        ->expectsOutput('Pruned 2 webhook delivery bodies and deleted 0 webhook deliveries.')
        ->assertSuccessful();

    expect($expired->refresh()->payload)->toBeNull()
        ->and($expired->payload_hash)->toBe($hash)
        ->and($expired->payload_bytes)->toBeGreaterThan(0)
        ->and($expired->status)->toBe(DeliveryStatus::Delivered)
        ->and($atTheLimit->refresh()->payload)->toBeNull()
        ->and($atTheLimit->status)->toBe(DeliveryStatus::Exhausted)
        ->and($kept->refresh()->payload)->not->toBeNull();

    $this->artisan('outbound:webhooks:prune')->expectsOutput('Pruned 0 webhook delivery bodies and deleted 0 webhook deliveries.')->assertSuccessful();
});

it('stops a pending delivery whose body expired, since nothing is left to sign', function (): void {
    retentionEndpoint();
    $pending = retainedDelivery('msg_pending', ['payload_expires_at' => '2026-11-23 09:00:00', 'next_attempt_at' => '2026-11-23 11:00:00']);

    $this->artisan('outbound:webhooks:prune')->assertSuccessful();
    $pending->refresh();

    expect($pending->payload)->toBeNull()
        ->and($pending->status)->toBe(DeliveryStatus::Exhausted)
        ->and($pending->exhausted_reason)->toBe(WebhookExhaustedReasonEnum::PayloadExpired)
        ->and($pending->next_attempt_at)->toBeNull();
});

it('leaves a delivery a worker is sending until its lease ends', function (): void {
    retentionEndpoint();
    $inFlight = retainedDelivery('msg_flight', ['payload_expires_at' => '2026-11-23 09:00:00', 'claimed_until' => '2026-11-23 10:01:00']);

    $this->artisan('outbound:webhooks:prune')->expectsOutput('Pruned 0 webhook delivery bodies and deleted 0 webhook deliveries.')->assertSuccessful();

    expect($inFlight->refresh()->payload)->not->toBeNull()
        ->and($inFlight->status)->toBe(DeliveryStatus::Pending);

    CarbonImmutable::setTestNow('2026-11-23 10:01:00');
    $this->artisan('outbound:webhooks:prune')->expectsOutput('Pruned 1 webhook delivery bodies and deleted 0 webhook deliveries.')->assertSuccessful();

    expect($inFlight->refresh()->payload)->toBeNull();
});

it('refuses a retention of less than a day, or rows kept shorter than bodies, when the application boots', function (array $config, string $message): void {
    $this->configBeforeBoot = $config;

    expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, $message);

    $this->configBeforeBoot = [];
    $this->refreshApplication();
})->with([
    'no days' => [['outbound.webhooks.payload_retention_days' => 0], 'outbound.webhooks.payload_retention_days must be at least 1.'],
    'negative' => [['outbound.webhooks.payload_retention_days' => -1], 'outbound.webhooks.payload_retention_days must be at least 1.'],
    'rows shorter than bodies' => [['outbound.webhooks.payload_retention_days' => 30, 'outbound.webhooks.delivery_retention_days' => 29], 'outbound.webhooks.delivery_retention_days must be at least outbound.webhooks.payload_retention_days.'],
]);

it('boots with a retention of one day and rows kept as long as bodies', function (): void {
    $this->configBeforeBoot = ['outbound.webhooks.payload_retention_days' => 1, 'outbound.webhooks.delivery_retention_days' => 1];
    $this->refreshApplication();

    expect(app(DatabaseDeliveryStore::class))->toBeInstanceOf(DatabaseDeliveryStore::class);

    $this->configBeforeBoot = [];
    $this->refreshApplication();
});

it('drops a pending redelivery request when the body expires', function (): void {
    retentionEndpoint();
    $operatorId = (int) DB::table('users')->insertGetId(['name' => 'Operator', 'email' => 'op@example.com', 'password' => 'x']);
    $pending = retainedDelivery('msg_asked', [
        'payload_expires_at' => '2026-11-23 09:00:00',
        'next_attempt_trigger' => 'manual',
        'next_attempt_triggered_by' => $operatorId,
    ]);

    $this->artisan('outbound:webhooks:prune')->assertSuccessful();
    $pending->refresh();

    expect($pending->status)->toBe(DeliveryStatus::Exhausted)
        ->and($pending->next_attempt_trigger)->toBeNull()
        ->and($pending->next_attempt_triggered_by)->toBeNull();
});

/*
 * Adversarial review of #37.
 */

it('keeps the body until the end of the retry plan when Retry-After is not honoured, or the retention when longer', function (): void {
    config()->set('outbound.webhooks.respect_retry_after', false);
    config()->set('outbound.webhooks.payload_retention_days', 2);
    foreach ([RetryPolicy::class, DatabaseDeliveryStore::class, WebhookDeliveryCoordinator::class] as $singleton) {
        $this->app->forgetInstance($singleton);
    }
    retentionEndpoint();

    // 9 retries on the standard delays plus their jitter, plus an hour of slack.
    expect(retainedDelivery('msg_1')->payload_expires_at?->toDateTimeString())->toBe('2026-11-26 14:39:07');
});

it('deletes settled deliveries and their attempts once the row retention ends', function (): void {
    config()->set('outbound.webhooks.delivery_retention_days', 90);
    $this->app->forgetInstance(DatabaseDeliveryStore::class);
    retentionEndpoint();
    $old = retainedDelivery('msg_old', ['status' => 'delivered', 'next_attempt_at' => null, 'created_at' => '2026-08-25 09:59:59']);
    $old->attempts()->make()->forceFill(['attempt' => 1, 'status' => 'delivered', 'webhook_timestamp' => 1, 'signature_schemes' => 'v1', 'attempted_at' => '2026-08-25 10:00:00'])->save();
    $atTheLimit = retainedDelivery('msg_limit', ['status' => 'exhausted', 'next_attempt_at' => null, 'created_at' => '2026-08-25 10:00:00']);
    $recent = retainedDelivery('msg_recent', ['status' => 'delivered', 'next_attempt_at' => null, 'created_at' => '2026-08-25 10:00:01']);
    $oldPending = retainedDelivery('msg_pending', ['created_at' => '2026-08-01 10:00:00', 'payload_expires_at' => '2026-12-31 00:00:00']);

    $this->artisan('outbound:webhooks:prune')
        ->expectsOutput('Pruned 0 webhook delivery bodies and deleted 2 webhook deliveries.')
        ->assertSuccessful();

    expect(WebhookDelivery::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(WebhookDeliveryAttempt::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->whereKey($atTheLimit->id)->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->whereKey($oldPending->id)->exists())->toBeTrue();
});

it('prunes in blocks, so a large backlog never takes one long transaction', function (): void {
    $endpoint = retentionEndpoint();
    $rows = [];
    foreach (range(1, 1001) as $number) {
        $rows[] = [
            'public_id' => (string) Str::uuid7(),
            'endpoint_id' => $endpoint->id,
            'event_id' => "msg_{$number}",
            'event_type' => 'invoice.paid',
            'occurred_at' => '2026-11-01 10:00:00',
            'payload_version' => '1',
            'status' => 'delivered',
            'max_attempts' => 10,
            'payload' => encrypt('{}', false),
            'payload_hash' => hash('sha256', '{}'),
            'payload_bytes' => 2,
            'payload_expires_at' => '2026-11-22 10:00:00',
            'created_at' => '2026-11-01 10:00:00',
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('webhook_deliveries')->insert($chunk);
    }
    $bodyUpdates = 0;
    DB::listen(function ($query) use (&$bodyUpdates): void {
        // SQLite and PostgreSQL quote identifiers with ", MySQL and MariaDB with `.
        if (preg_match('/^update .*[`"]payload[`"] = \?/', $query->sql) === 1) {
            $bodyUpdates++;
        }
    });

    $this->artisan('outbound:webhooks:prune')->expectsOutput('Pruned 1001 webhook delivery bodies and deleted 0 webhook deliveries.')->assertSuccessful();

    expect(WebhookDelivery::query()->whereNotNull('payload')->count())->toBe(0)
        ->and($bodyUpdates)->toBe(3);
});

it('refuses to redeliver a body that has expired but is not pruned yet', function (): void {
    $endpoint = retentionEndpoint();
    $expired = retainedDelivery('msg_expired', ['status' => 'exhausted', 'next_attempt_at' => null, 'payload_expires_at' => '2026-11-23 10:00:00', 'created_at' => '2026-11-22 12:00:00']);

    expect(fn () => app(WebhookDeliveryCoordinator::class)->redeliver($expired))->toThrow(WebhookException::class, 'Webhook delivery body has been pruned or has expired; it cannot be redelivered.')
        ->and(app(WebhookDeliveryCoordinator::class)->redeliverRange($endpoint, CarbonImmutable::parse('2026-11-22', 'UTC'), CarbonImmutable::parse('2026-11-23', 'UTC'), [DeliveryStatus::Exhausted]))->toBe(0)
        ->and($expired->refresh()->status)->toBe(DeliveryStatus::Exhausted);
});
