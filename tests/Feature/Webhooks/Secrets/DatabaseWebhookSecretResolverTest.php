<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;
use Qiick\Outbound\Webhooks\DatabaseWebhookSecretResolver;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

const RESOLVER_PRIMARY_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function resolverEndpoint(): WebhookEndpoint
{
    return WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function resolverSecret(WebhookEndpoint $endpoint, array $attributes = []): WebhookEndpointSecret
{
    return $endpoint->secrets()->forceCreate([
        'secret' => WebhookSecret::generate()->serialized(),
        ...$attributes,
    ]);
}

it('signs with the primary first, then the grace secrets latest expiry first, newest first on a tie', function (): void {
    $endpoint = resolverEndpoint();
    $primary = resolverSecret($endpoint, ['secret' => RESOLVER_PRIMARY_SECRET, 'is_primary' => true]);
    $laterExpiry = resolverSecret($endpoint, ['expires_at' => '2026-11-24 10:00:00']);
    $earlierExpiry = resolverSecret($endpoint, ['expires_at' => '2026-11-23 12:00:00']);
    $tiedNewer = resolverSecret($endpoint, ['expires_at' => '2026-11-23 12:00:00']);

    $signingSet = (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint);

    expect($signingSet->secretPublicIds())->toBe([
        $primary->public_id,
        $laterExpiry->public_id,
        $tiedNewer->public_id,
        $earlierExpiry->public_id,
    ])
        ->and($signingSet->secrets()[0]->serialized())->toBe(RESOLVER_PRIMARY_SECRET)
        ->and($signingSet->secrets()[1]->serialized())->toBe($laterExpiry->secret);
});

it('leaves out revoked, expired, never-expiring old, v2a and other endpoints\' secrets', function (): void {
    $endpoint = resolverEndpoint();
    $primary = resolverSecret($endpoint, ['is_primary' => true]);
    resolverSecret($endpoint, ['expires_at' => '2026-11-24 10:00:00', 'revoked_at' => '2026-11-23 09:00:00']);
    resolverSecret($endpoint, ['expires_at' => '2026-11-23 10:00:00']);
    resolverSecret($endpoint, ['expires_at' => '2026-11-23 09:59:59']);
    resolverSecret($endpoint);
    resolverSecret($endpoint, ['scheme' => 'v2a', 'secret' => null, 'public_key' => 'whpk_x', 'expires_at' => '2026-11-24 10:00:00']);
    resolverSecret(resolverEndpoint(), ['expires_at' => '2026-11-24 10:00:00']);

    $signingSet = (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint);

    expect($signingSet->secretPublicIds())->toBe([$primary->public_id]);
});

it('keeps a secret that expires one second from now', function (): void {
    $endpoint = resolverEndpoint();
    resolverSecret($endpoint, ['is_primary' => true]);
    $grace = resolverSecret($endpoint, ['expires_at' => '2026-11-23 10:00:01']);

    $signingSet = (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint);

    expect($signingSet->secretPublicIds()[1])->toBe($grace->public_id);
});

it('refuses an endpoint without an active primary secret, even when grace secrets remain', function (): void {
    $endpoint = resolverEndpoint();
    resolverSecret($endpoint, ['is_primary' => true, 'revoked_at' => '2026-11-23 09:00:00']);
    resolverSecret($endpoint, ['expires_at' => '2026-11-24 10:00:00']);

    (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint);
})->throws(WebhookException::class, 'Webhook endpoint has no active primary signing secret.');

it('refuses an endpoint with two active primary secrets', function (): void {
    $endpoint = resolverEndpoint();
    resolverSecret($endpoint, ['is_primary' => true]);
    resolverSecret($endpoint, ['is_primary' => true]);

    (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint);
})->throws(WebhookException::class, 'Webhook endpoint has more than one active primary signing secret.');

it('refuses a v1 row without secret bytes', function (): void {
    $endpoint = resolverEndpoint();
    resolverSecret($endpoint, ['is_primary' => true, 'secret' => null]);

    (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint);
})->throws(WebhookException::class, 'Webhook signing secret has no secret bytes.');

it('accepts a legacy raw secret only when the migration switch is on', function (): void {
    $endpoint = resolverEndpoint();
    $rawBase64 = base64_encode(str_repeat('k', 32));
    resolverSecret($endpoint, ['is_primary' => true, 'secret' => $rawBase64, 'is_legacy_raw' => true]);

    $signingSet = (new DatabaseWebhookSecretResolver(allowRawLegacySecret: true))->signingSetFor($endpoint);

    expect($signingSet->secrets()[0]->signingKey())->toBe(str_repeat('k', 32));

    expect(fn () => (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint))
        ->toThrow(WebhookException::class, 'Webhook secret must use the whsec_ prefix.');
});

it('refuses a raw secret that is not flagged as legacy, even with the switch on', function (): void {
    $endpoint = resolverEndpoint();
    resolverSecret($endpoint, ['is_primary' => true, 'secret' => base64_encode(str_repeat('k', 32))]);

    (new DatabaseWebhookSecretResolver(allowRawLegacySecret: true))->signingSetFor($endpoint);
})->throws(WebhookException::class, 'Webhook secret must use the whsec_ prefix.');
