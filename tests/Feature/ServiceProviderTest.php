<?php

declare(strict_types=1);

use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\DnsHostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;

it('merges the package defaults into the outbound config', function (): void {
    expect(config('outbound'))->toMatchArray([
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
        ],
        'webhooks' => [
            'replay_tolerance_seconds' => 300,
            'max_payload_bytes' => 1_048_576,
            'max_signatures' => 8,
            'max_secrets' => 4,
            'retry_preset' => 'standard',
            'retry_jitter_seconds' => 30,
            'allow_raw_legacy_secret' => false,
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
