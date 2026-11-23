<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookMessage;

/*
 * Standards review R5 and M12 (Luca's Q3 of 2026-11-22): the encrypted body
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
    'configured' => [7, '2026-11-30 10:00:00'],
]);

it('clears expired bodies and keeps their hash and length', function (): void {
    retentionEndpoint();
    $expired = retainedDelivery('msg_expired', ['status' => 'delivered', 'next_attempt_at' => null, 'payload_expires_at' => '2026-11-23 09:59:59']);
    $atTheLimit = retainedDelivery('msg_limit', ['status' => 'exhausted', 'next_attempt_at' => null, 'payload_expires_at' => '2026-11-23 10:00:00']);
    $kept = retainedDelivery('msg_kept', ['status' => 'delivered', 'next_attempt_at' => null, 'payload_expires_at' => '2026-11-23 10:00:01']);
    $hash = $expired->payload_hash;

    $this->artisan('outbound:webhooks:prune')
        ->expectsOutput('Pruned 2 webhook delivery bodies.')
        ->assertSuccessful();

    expect($expired->refresh()->payload)->toBeNull()
        ->and($expired->payload_hash)->toBe($hash)
        ->and($expired->payload_bytes)->toBeGreaterThan(0)
        ->and($expired->status)->toBe(DeliveryStatus::Delivered)
        ->and($atTheLimit->refresh()->payload)->toBeNull()
        ->and($atTheLimit->status)->toBe(DeliveryStatus::Exhausted)
        ->and($kept->refresh()->payload)->not->toBeNull();

    $this->artisan('outbound:webhooks:prune')->expectsOutput('Pruned 0 webhook delivery bodies.')->assertSuccessful();
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

    $this->artisan('outbound:webhooks:prune')->expectsOutput('Pruned 0 webhook delivery bodies.')->assertSuccessful();

    expect($inFlight->refresh()->payload)->not->toBeNull()
        ->and($inFlight->status)->toBe(DeliveryStatus::Pending);

    CarbonImmutable::setTestNow('2026-11-23 10:01:00');
    $this->artisan('outbound:webhooks:prune')->expectsOutput('Pruned 1 webhook delivery bodies.')->assertSuccessful();

    expect($inFlight->refresh()->payload)->toBeNull();
});

it('refuses a retention shorter than the retry schedule when the application boots', function (int $retentionDays): void {
    $this->configBeforeBoot = ['outbound.webhooks.payload_retention_days' => $retentionDays];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, 'outbound.webhooks.payload_retention_days must be at least 4');

    $this->configBeforeBoot = [];
    $this->refreshApplication();
})->with([0, 3]);
