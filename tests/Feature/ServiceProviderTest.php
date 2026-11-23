<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Ssrf\DnsHostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\DatabaseWebhookSecretResolver;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\UnsupportedWebhookIdCacheStoreException;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;

it('merges the package defaults into the outbound config', function (): void {
    expect(config('outbound'))->toMatchArray([
        'migrations' => [
            'load' => true,
        ],
        'ssrf' => [
            'allow_private_hosts' => false,
            'https_only' => false,
        ],
        'http' => [
            'timeout_seconds' => 5,
            'connect_timeout_seconds' => 3,
            'max_timeout_seconds' => 60,
            'max_connect_timeout_seconds' => 30,
            'total_deadline_seconds' => 30,
            'max_response_bytes' => 1_048_576,
            'max_redirects' => 0,
            'egress_proxy' => null,
        ],
        'webhooks' => [
            'replay_tolerance_seconds' => 300,
            'max_payload_bytes' => 1_048_576,
            'max_signatures' => 8,
            'max_secrets' => 4,
            'secret_rotation_grace_seconds' => 86_400,
            'retry_preset' => 'standard',
            'retry_jitter_seconds' => 30,
            'timeout_seconds' => 15,
            'respect_retry_after' => true,
            'retry_after_cap_seconds' => 86_400,
            'default_max_concurrency' => 2,
            'allow_endpoint_private_hosts' => false,
            'payload_retention_days' => 30,
            'delivery_retention_days' => 90,
            'auto_disable' => [
                'enabled' => true,
                'no_success_days' => 5,
                'min_consecutive_failures' => 10,
                'notify_owner' => true,
                'endpoint_url' => null,
            ],
            'allow_raw_legacy_secret' => false,
            'scope_column' => null,
            'users_table' => 'users',
        ],
    ]);
});

it('lets an app publish the config file', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'outbound-config', '--force' => true])
        ->assertSuccessful();

    expect(config_path('outbound.php'))->toBeFile();

    unlink(config_path('outbound.php'));
});

it('binds HostResolver, RemoteUrlGuard, PinnedHttpClient and webhook signer/verifier as singletons', function (): void {
    expect($this->app->make(HostResolver::class))->toBeInstanceOf(DnsHostResolver::class)
        ->and($this->app->make(RemoteUrlGuard::class))->toBeInstanceOf(RemoteUrlGuard::class)
        ->and($this->app->make(RemoteUrlGuard::class))->toBe($this->app->make(RemoteUrlGuard::class))
        ->and($this->app->make(PinnedHttpClient::class))->toBeInstanceOf(PinnedHttpClient::class)
        ->and($this->app->make(PinnedHttpClient::class))->toBe($this->app->make(PinnedHttpClient::class))
        ->and($this->app->make(StandardWebhookSigner::class))->toBeInstanceOf(StandardWebhookSigner::class)
        ->and($this->app->make(StandardWebhookSigner::class))->toBe($this->app->make(StandardWebhookSigner::class))
        ->and($this->app->make(StandardWebhookVerifier::class))->toBeInstanceOf(StandardWebhookVerifier::class)
        ->and($this->app->make(StandardWebhookVerifier::class))->toBe($this->app->make(StandardWebhookVerifier::class))
        ->and($this->app->make(SeenWebhookIdStore::class))->toBeInstanceOf(CacheSeenWebhookIdStore::class)
        ->and($this->app->make(SeenWebhookIdStore::class))->toBe($this->app->make(SeenWebhookIdStore::class));
});

it('fails clearly when resolving the verifier with ArrayStore outside testing', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->forgetInstance(SeenWebhookIdStore::class);
    $this->app->forgetInstance(StandardWebhookVerifier::class);

    expect(fn () => $this->app->make(StandardWebhookVerifier::class))
        ->toThrow(UnsupportedWebhookIdCacheStoreException::class, 'refuses ArrayStore outside tests');
});

it('binds the secret resolver and rotator as singletons', function (): void {
    expect($this->app->make(WebhookSecretResolver::class))->toBeInstanceOf(DatabaseWebhookSecretResolver::class)
        ->and($this->app->make(WebhookSecretResolver::class))->toBe($this->app->make(WebhookSecretResolver::class))
        ->and($this->app->make(SecretRotator::class))->toBe($this->app->make(SecretRotator::class));
});

it('configures the rotator grace period and secret cap from config', function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    config()->set('outbound.webhooks.secret_rotation_grace_seconds', 60);
    config()->set('outbound.webhooks.max_secrets', 2);
    // Boot already built the singleton (to check max_secrets); build it again.
    $this->app->forgetInstance(SecretRotator::class);
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $rotator = $this->app->make(SecretRotator::class);
    $old = $rotator->issue($endpoint);
    $rotator->rotate($endpoint);

    expect($old->refresh()->expires_at?->toDateTimeString())->toBe('2026-11-23 10:01:00')
        ->and(fn () => $rotator->rotate($endpoint))
        ->toThrow(WebhookException::class, 'Rotating would exceed 2 signing secrets');

    CarbonImmutable::setTestNow();
});

it('lets the resolver read legacy raw secrets only when the config allows them', function (bool $isAllowed): void {
    config()->set('outbound.webhooks.allow_raw_legacy_secret', $isAllowed);
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $endpoint->secrets()->forceCreate(['secret' => base64_encode(str_repeat('k', 32)), 'is_legacy_raw' => true, 'is_primary' => true]);
    $resolve = fn () => $this->app->make(WebhookSecretResolver::class)->signingSetFor($endpoint);

    if ($isAllowed) {
        expect($resolve()->secrets())->toHaveCount(1);

        return;
    }

    expect($resolve)->toThrow(WebhookException::class, 'whsec_ prefix');
})->with([true, false]);

it('refuses to boot with a max_secrets above four', function (): void {
    $this->configBeforeBoot = ['outbound.webhooks.max_secrets' => 5];

    expect(fn () => $this->refreshApplication())->toThrow(WebhookException::class, 'max_secrets must be between 1 and 4');

    $this->configBeforeBoot = [];
    $this->refreshApplication();
});

it('builds the pinned client with the configured egress proxy, or none', function (?string $configuredProxy, ?string $expectedProxy): void {
    config()->set('outbound.http.egress_proxy', $configuredProxy);
    $this->app->forgetInstance(PinnedHttpClient::class);

    $egressProxy = (new ReflectionProperty(PinnedHttpClient::class, 'egressProxy'))->getValue($this->app->make(PinnedHttpClient::class));

    expect($egressProxy === null ? null : $egressProxy->url())->toBe($expectedProxy);
})->with([
    'unset' => [null, null],
    'empty, as an unset env variable leaves it' => ['', null],
    'configured' => ['http://proxy.internal:3128', 'http://proxy.internal:3128'],
]);

it('refuses to boot with an egress proxy that is not an http(s) proxy URL', function (): void {
    $this->configBeforeBoot = ['outbound.http.egress_proxy' => 'socks5h://proxy.internal:1080'];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, 'outbound.http.egress_proxy must be an http:// or https:// proxy URL');

    $this->configBeforeBoot = [];
    $this->refreshApplication();
});
