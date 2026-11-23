<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Qiick\Outbound\Delivery\DuplicateWebhookDeliveryException;
use Qiick\Outbound\Delivery\WebhookDeliveryStorageException;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;
use Qiick\Outbound\Models\WebhookSubscription;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

/*
 * Mass assignment takes an explicit, minimal allow-list per model. Secret
 * bytes and the stored body (with its hash) are set only through the models'
 * dedicated methods, and never leave the model through arrays or JSON.
 */

/**
 * @return array<string, array{class-string<Model>, list<string>}>
 */
function sensitiveAttributesByModel(): array
{
    return [
        'endpoint secret' => [WebhookEndpointSecret::class, ['secret']],
        'delivery' => [WebhookDelivery::class, ['payload', 'payload_hash']],
    ];
}

it('allows mass assignment of the listed attributes only', function (string $modelClass, array $fillableAttributes): void {
    /** @var Model $model */
    $model = new $modelClass;

    expect($model->getFillable())->toBe($fillableAttributes)
        ->and($model->getGuarded())->toBe(['*'])
        ->and($model->isFillable('id'))->toBeFalse()
        ->and($model->isFillable('public_id'))->toBeFalse();
})->with([
    'endpoint' => [WebhookEndpoint::class, [
        'name', 'description', 'url', 'payload_version', 'legacy_profile', 'https_only',
        'allow_private_hosts', 'timeout_seconds', 'max_concurrency', 'rate_limit_per_minute',
        'retry_preset', 'max_attempts', 'auto_disable', 'headers', 'metadata', 'created_by',
    ]],
    'endpoint secret' => [WebhookEndpointSecret::class, ['metadata']],
    'subscription' => [WebhookSubscription::class, ['event_type']],
    'delivery' => [WebhookDelivery::class, [
        'event_id', 'event_type', 'occurred_at', 'payload_version', 'max_attempts', 'trigger', 'metadata',
    ]],
    'attempt' => [WebhookDeliveryAttempt::class, []],
]);

it('lets the product fill its own scope column on endpoints', function (): void {
    config()->set('outbound.webhooks.scope_column', 'project_id');

    expect((new WebhookEndpoint)->isFillable('project_id'))->toBeTrue();

    config()->set('outbound.webhooks.scope_column', '');

    expect((new WebhookEndpoint)->getFillable())->not->toContain('');
});

it('never mass-assigns a sensitive attribute', function (string $modelClass, array $sensitiveAttributes): void {
    foreach ($sensitiveAttributes as $sensitiveAttribute) {
        /** @var Model $model */
        $model = new $modelClass;
        $model->fill([$sensitiveAttribute => 'whsec_forged']);

        expect($model->isFillable($sensitiveAttribute))->toBeFalse()
            ->and($model->getAttributes())->not->toHaveKey($sensitiveAttribute);
    }
})->with(sensitiveAttributesByModel());

it('never shows a sensitive attribute in arrays or JSON', function (string $modelClass, array $sensitiveAttributes): void {
    /** @var Model $model */
    $model = new $modelClass;
    $model->forceFill(array_fill_keys($sensitiveAttributes, 'sensitive-value'));

    foreach ($sensitiveAttributes as $sensitiveAttribute) {
        expect($model->toArray())->not->toHaveKey($sensitiveAttribute)
            ->and(json_decode($model->toJson(), true))->not->toHaveKey($sensitiveAttribute)
            ->and($model->toJson())->not->toContain('sensitive-value');
    }
})->with(sensitiveAttributesByModel());

it('stores a secret only through useSecret, in canonical whsec_ form', function (): void {
    $secret = WebhookSecret::parseAllowingLegacyRaw(base64_encode(str_repeat('k', 32)), allowRaw: true);
    $endpointSecret = (new WebhookEndpointSecret)->forceFill(['is_legacy_raw' => true]);

    expect($endpointSecret->useSecret($secret))->toBe($endpointSecret)
        ->and($endpointSecret->secret)->toBe($secret->serialized())
        ->and($endpointSecret->is_legacy_raw)->toBeFalse();
});

it('stores a body only through usePayload, with its SHA-256 hash and byte length', function (): void {
    $delivery = new WebhookDelivery;
    $rawBody = '{"type":"invoice.paid","data":{"name":"Zoë"}}';

    expect($delivery->usePayload($rawBody))->toBe($delivery)
        ->and($delivery->payload)->toBe($rawBody)
        ->and($delivery->payload_hash)->toBe(hash('sha256', $rawBody))
        ->and($delivery->payload_bytes)->toBe(strlen($rawBody))
        ->and($delivery->payload_bytes)->not->toBe(mb_strlen($rawBody))
        ->and($delivery->isFillable('payload_bytes'))->toBeFalse();
});

it('encrypts the custom request headers at rest and never shows them', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.com/receive',
        'headers' => ['Authorization' => 'Bearer TOKEN123'],
    ]);
    $storedHeaders = (string) DB::table('webhook_endpoints')->value('headers');

    expect($storedHeaders)->not->toContain('TOKEN123')
        ->and(WebhookEndpoint::query()->findOrFail($endpoint->id)->headers)->toBe(['Authorization' => 'Bearer TOKEN123'])
        ->and($endpoint->toArray())->not->toHaveKey('headers')
        ->and($endpoint->toJson())->not->toContain('TOKEN123');
});

it('refuses to replicate a signing secret, which would copy the key and the primary flag', function (string $replicateMethod): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $secret = $endpoint->secrets()->make()->useSecret(WebhookSecret::generate())->forceFill(['is_primary' => true]);
    $secret->save();

    expect(fn () => $secret->{$replicateMethod}())
        ->toThrow(WebhookException::class, 'Webhook secrets cannot be replicated; issue or rotate them with SecretRotator.')
        ->and(WebhookEndpointSecret::query()->count())->toBe(1);
})->with(['replicate', 'replicateQuietly']);

/**
 * @param  array<string, mixed>  $attributes
 */
function unsavedDelivery(WebhookEndpoint $endpoint, string $rawBody, array $attributes = []): WebhookDelivery
{
    return $endpoint->deliveries()->make()->usePayload($rawBody)->forceFill([
        'event_id' => 'msg_1',
        'event_type' => 'invoice.paid',
        'occurred_at' => '2026-11-23 10:00:00',
        'payload_version' => '1',
        'max_attempts' => 10,
        ...$attributes,
    ]);
}

it('keeps the body hash and every other binding out of a failed delivery save', function (): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $rawBody = '{"type":"ping"}';
    $delivery = unsavedDelivery($endpoint, $rawBody, ['event_type' => null]);

    try {
        $delivery->save();
        $thrown = null;
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    $chainMessages = [];
    for ($link = $thrown; $link !== null; $link = $link->getPrevious()) {
        $chainMessages[] = $link->getMessage();
    }

    expect($thrown)->toBeInstanceOf(WebhookDeliveryStorageException::class)
        ->and($thrown)->not->toBeInstanceOf(DuplicateWebhookDeliveryException::class)
        ->and($thrown?->getMessage())->toMatch('/^Webhook delivery could not be saved \(SQLSTATE \w{5}\)\.$/')
        ->and($thrown?->getPrevious())->toBeNull()
        ->and(implode("\n", $chainMessages))->not->toContain(hash('sha256', $rawBody))->not->toContain('msg_1')
        ->and((string) $thrown)->not->toContain(hash('sha256', $rawBody));
});

it('reports a second delivery of the same event to the same endpoint as a duplicate, without bindings', function (): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    unsavedDelivery($endpoint, '{"type":"ping"}')->save();

    try {
        unsavedDelivery($endpoint, '{"type":"ping"}')->save();
        $thrown = null;
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(DuplicateWebhookDeliveryException::class)
        ->and($thrown?->getMessage())->toMatch('/^Webhook delivery could not be saved \(SQLSTATE \w{5}\)\.$/')
        ->and($thrown?->getPrevious())->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(1);
});

it('saves and updates a delivery normally', function (): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $delivery = unsavedDelivery($endpoint, '{"type":"ping"}');

    expect($delivery->save())->toBeTrue()
        ->and($delivery->forceFill(['attempts_count' => 1])->save())->toBeTrue()
        ->and(WebhookDelivery::query()->sole()->attempts_count)->toBe(1);
});
