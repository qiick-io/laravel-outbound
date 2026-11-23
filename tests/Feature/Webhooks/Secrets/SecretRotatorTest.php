<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Qiick\Outbound\Enums\WebhookSignatureSchemeEnum;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;
use Qiick\Outbound\Webhooks\DatabaseWebhookSecretResolver;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function rotatorEndpoint(): WebhookEndpoint
{
    return WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
}

/**
 * @return list<string>
 */
function signingPublicIds(WebhookEndpoint $endpoint): array
{
    return (new DatabaseWebhookSecretResolver)->signingSetFor($endpoint)->secretPublicIds();
}

it('issues a v1 whsec_ primary secret whose plaintext the caller can show once', function (): void {
    $endpoint = rotatorEndpoint();

    $issued = (new SecretRotator)->issue($endpoint);
    $stored = WebhookEndpointSecret::query()->findOrFail($issued->id);

    expect($issued->secret)->toStartWith('whsec_')
        ->and(strlen(WebhookSecret::parse((string) $issued->secret)->signingKey()))->toBe(32)
        ->and($stored->secret)->toBe($issued->secret)
        ->and($stored->is_primary)->toBeTrue()
        ->and($stored->is_legacy_raw)->toBeFalse()
        ->and($stored->scheme)->toBe(WebhookSignatureSchemeEnum::V1)
        ->and($stored->expires_at)->toBeNull()
        ->and($stored->revoked_at)->toBeNull()
        ->and($stored->endpoint_id)->toBe($endpoint->id)
        ->and(signingPublicIds($endpoint))->toBe([$issued->public_id]);
});

it('refuses to issue a second primary secret', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $rotator->issue($endpoint);

    $rotator->issue($endpoint);
})->throws(WebhookException::class, 'Webhook endpoint already has a primary secret; rotate it instead.');

it('issues again once the only primary secret has been revoked out of band', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $revoked = $rotator->issue($endpoint);
    $revoked->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

    $issued = $rotator->issue($endpoint);

    expect(signingPublicIds($endpoint))->toBe([$issued->public_id]);
});

it('rotates to a new primary and keeps the old one signing for the configured grace period', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator(defaultGraceSeconds: 3600);
    $old = $rotator->issue($endpoint);

    $new = $rotator->rotate($endpoint);
    $old->refresh();

    expect($new->is_primary)->toBeTrue()
        ->and($new->secret)->not->toBe($old->secret)
        ->and($old->is_primary)->toBeFalse()
        ->and($old->revoked_at)->toBeNull()
        ->and($old->expires_at?->toDateTimeString())->toBe('2026-11-23 11:00:00')
        ->and(signingPublicIds($endpoint))->toBe([$new->public_id, $old->public_id]);

    CarbonImmutable::setTestNow('2026-11-23 11:00:00');

    expect(signingPublicIds($endpoint))->toBe([$new->public_id]);
});

it('uses a 24 hour grace period by default and an explicit one when given', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $first = $rotator->issue($endpoint);
    $second = $rotator->rotate($endpoint);
    $rotator->rotate($endpoint, graceSeconds: 1);

    expect($first->refresh()->expires_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
        ->and($second->refresh()->expires_at?->toDateTimeString())->toBe('2026-11-23 10:00:01');
});

it('revokes the old primary at once when rotated with a zero grace period', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $old = $rotator->issue($endpoint);

    $new = $rotator->rotate($endpoint, graceSeconds: 0);
    $old->refresh();

    expect($old->is_primary)->toBeFalse()
        ->and($old->revoked_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and($old->expires_at)->toBeNull()
        ->and(signingPublicIds($endpoint))->toBe([$new->public_id]);
});

it('refuses a negative grace period and leaves the secrets untouched', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $primary = $rotator->issue($endpoint);

    expect(fn () => $rotator->rotate($endpoint, graceSeconds: -1))
        ->toThrow(WebhookException::class, 'Webhook secret grace period must not be negative.')
        ->and(signingPublicIds($endpoint))->toBe([$primary->public_id]);
});

it('refuses to rotate an endpoint that has no primary secret', function (): void {
    (new SecretRotator)->rotate(rotatorEndpoint());
})->throws(WebhookException::class, 'Webhook endpoint has no primary secret; issue one first.');

it('allows up to four signing secrets and refuses a rotation that would exceed them', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $rotator->issue($endpoint);
    $rotator->rotate($endpoint);
    $rotator->rotate($endpoint);
    $rotator->rotate($endpoint);
    $before = signingPublicIds($endpoint);

    expect($before)->toHaveCount(4)
        ->and(fn () => $rotator->rotate($endpoint))
        ->toThrow(WebhookException::class, 'Rotating would exceed 4 signing secrets; revoke an old secret first.')
        ->and(signingPublicIds($endpoint))->toBe($before)
        ->and(WebhookEndpointSecret::query()->count())->toBe(4);
});

it('still rotates a full set with a zero grace period, because the old primary stops signing', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator(maxSecrets: 2);
    $rotator->issue($endpoint);
    $rotator->rotate($endpoint);

    $new = $rotator->rotate($endpoint, graceSeconds: 0);

    expect(signingPublicIds($endpoint))->toHaveCount(2)
        ->and(signingPublicIds($endpoint)[0])->toBe($new->public_id);
});

it('does not count expired, revoked or other endpoints\' secrets against the limit', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator(maxSecrets: 2);
    $rotator->issue($endpoint);
    $endpoint->secrets()->forceCreate(['secret' => WebhookSecret::generate()->serialized(), 'expires_at' => '2026-11-23 10:00:00']);
    $endpoint->secrets()->forceCreate(['secret' => WebhookSecret::generate()->serialized(), 'expires_at' => '2026-11-24 10:00:00', 'revoked_at' => '2026-11-23 09:00:00']);
    $otherEndpoint = rotatorEndpoint();
    $rotator->issue($otherEndpoint);
    $rotator->rotate($otherEndpoint);

    $new = $rotator->rotate($endpoint);

    expect(signingPublicIds($endpoint))->toHaveCount(2)
        ->and(signingPublicIds($endpoint)[0])->toBe($new->public_id);
});

it('counts a grace secret written outside the rotator against the limit', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator(maxSecrets: 2);
    $rotator->issue($endpoint);
    $endpoint->secrets()->forceCreate(['secret' => WebhookSecret::generate()->serialized(), 'expires_at' => '2026-11-24 10:00:00']);

    $rotator->rotate($endpoint);
})->throws(WebhookException::class, 'Rotating would exceed 2 signing secrets; revoke an old secret first.');

it('revokes a grace secret so it stops signing at once, and keeps the first revocation time', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $old = $rotator->issue($endpoint);
    $new = $rotator->rotate($endpoint);

    $rotator->revoke($old);
    CarbonImmutable::setTestNow('2026-11-23 12:00:00');
    $rotator->revoke($old);

    expect($old->refresh()->revoked_at?->toDateTimeString())->toBe('2026-11-23 10:00:00')
        ->and(signingPublicIds($endpoint))->toBe([$new->public_id]);
});

it('refuses to revoke the primary secret, even through a stale model', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $primary = $rotator->issue($endpoint);
    $stale = WebhookEndpointSecret::query()->findOrFail($primary->id);
    $stale->is_primary = false;

    expect(fn () => $rotator->revoke($stale))
        ->toThrow(WebhookException::class, 'The primary webhook secret cannot be revoked; rotate it with a zero grace period instead.')
        ->and(signingPublicIds($endpoint))->toBe([$primary->public_id]);
});

it('locks the endpoint row while it changes secrets, so concurrent rotations cannot leave two primaries', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator;
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $old = $rotator->issue($endpoint);
    $rotator->rotate($endpoint);
    $rotator->revoke($old);

    $lockingReads = array_values(array_filter(
        $statements,
        static fn (string $statement): bool => str_contains($statement, 'webhook_endpoints') && str_starts_with($statement, 'select'),
    ));

    expect($lockingReads)->toHaveCount(3);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        foreach ($lockingReads as $lockingRead) {
            expect($lockingRead)->toContain('for update');
        }
    }
});

it('refuses a zero-grace rotation when the set is already over the limit', function (): void {
    $endpoint = rotatorEndpoint();
    $rotator = new SecretRotator(maxSecrets: 2);
    $rotator->issue($endpoint);
    $endpoint->secrets()->forceCreate(['secret' => WebhookSecret::generate()->serialized(), 'expires_at' => '2026-11-24 10:00:00']);
    $endpoint->secrets()->forceCreate(['secret' => WebhookSecret::generate()->serialized(), 'expires_at' => '2026-11-24 10:00:00']);

    $rotator->rotate($endpoint, graceSeconds: 0);
})->throws(WebhookException::class, 'Rotating would exceed 2 signing secrets; revoke an old secret first.');
