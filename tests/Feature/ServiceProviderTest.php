<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Contracts\MetadataPolicyContract;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
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
use Qiick\Outbound\Webhooks\WebhookSecret;
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
            'legacy_profiles' => [],
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

it('refuses to boot with an egress proxy that is not an http(s) proxy URL', function (mixed $configuredProxy): void {
    $this->configBeforeBoot = ['outbound.http.egress_proxy' => $configuredProxy];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, 'outbound.http.egress_proxy must be an http:// or https:// proxy URL');

    $this->configBeforeBoot = [];
    $this->refreshApplication();
})->with([
    'socks' => ['socks5h://proxy.internal:1080'],
    // OUTBOUND_EGRESS_PROXY=true reaches the config as a boolean: never "no proxy".
    'a boolean' => [true],
    'an array from a published config' => [['http' => 'http://proxy.internal:3128']],
]);

it('refuses to boot with a config value of the wrong type, naming the key', function (string $key, mixed $value, string $message): void {
    $this->configBeforeBoot = [$key => $value];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, $message);

    $this->configBeforeBoot = [];
    $this->refreshApplication();
})->with([
    'an integer as a string' => ['outbound.webhooks.payload_retention_days', '30', 'outbound.webhooks.payload_retention_days must be an integer.'],
    'a boolean as a string' => ['outbound.migrations.load', 'yes', 'outbound.migrations.load must be true or false.'],
    // Keys read only when a verifier, retry policy or coordinator is first built.
    'the replay tolerance' => ['outbound.webhooks.replay_tolerance_seconds', '300', 'outbound.webhooks.replay_tolerance_seconds must be an integer.'],
    'the signature cap' => ['outbound.webhooks.max_signatures', 5.0, 'outbound.webhooks.max_signatures must be an integer.'],
    'the retry jitter' => ['outbound.webhooks.retry_jitter_seconds', '5', 'outbound.webhooks.retry_jitter_seconds must be an integer.'],
    'the Retry-After switch' => ['outbound.webhooks.respect_retry_after', 1, 'outbound.webhooks.respect_retry_after must be true or false.'],
    'the webhook timeout' => ['outbound.webhooks.timeout_seconds', '10', 'outbound.webhooks.timeout_seconds must be an integer.'],
    'the default concurrency' => ['outbound.webhooks.default_max_concurrency', '4', 'outbound.webhooks.default_max_concurrency must be an integer.'],
    'the response cap' => ['outbound.http.max_response_bytes', '65536', 'outbound.http.max_response_bytes must be an integer.'],
    'the legacy secret switch' => ['outbound.webhooks.allow_raw_legacy_secret', 'false', 'outbound.webhooks.allow_raw_legacy_secret must be true or false.'],
    'a nested switch' => ['outbound.webhooks.auto_disable.notify_owner', 'no', 'outbound.webhooks.auto_disable.notify_owner must be true or false.'],
    'a whole section' => ['outbound.ssrf', 'strict', 'outbound.ssrf must be an array.'],
    'the retry preset' => ['outbound.webhooks.retry_preset', 'fast', 'outbound.webhooks.retry_preset must be standard or short.'],
    'the scope column' => ['outbound.webhooks.scope_column', 7, 'outbound.webhooks.scope_column must be a string.'],
    'an explicit null where an integer belongs' => ['outbound.http.max_response_bytes', null, 'outbound.http.max_response_bytes must be an integer.'],
    'the legacy profiles as a string' => ['outbound.webhooks.legacy_profiles', 'legacy-1', 'outbound.webhooks.legacy_profiles must map a name to a LegacyWebhookProfileContract binding.'],
    'a legacy profile without a name' => ['outbound.webhooks.legacy_profiles', ['test.legacy-profile'], 'outbound.webhooks.legacy_profiles must map a name to a LegacyWebhookProfileContract binding.'],
    'a legacy profile without a binding name' => ['outbound.webhooks.legacy_profiles', ['legacy' => false], 'outbound.webhooks.legacy_profiles must map a name to a LegacyWebhookProfileContract binding.'],
    'the endpoint page URL as an array' => ['outbound.webhooks.auto_disable.endpoint_url', ['https://app.example/hooks'], 'outbound.webhooks.auto_disable.endpoint_url must be a string.'],
    'the users table as a boolean' => ['outbound.webhooks.users_table', false, 'outbound.webhooks.users_table must be a string.'],
]);

it('validates every shipped config key at boot', function (string $key, mixed $invalidValue): void {
    $this->configBeforeBoot = [$key => $invalidValue];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, $key);
})->with(function (): iterable {
    $defaults = require __DIR__.'/../../config/outbound.php';

    foreach (Arr::dot(['outbound' => $defaults]) as $key => $value) {
        yield $key => [$key, is_array($value) ? false : []];
    }
});

it('reads the types from the shipped schema, not from the environment', function (): void {
    // env() turns "false" into a boolean: the default must not change type with it.
    putenv('OUTBOUND_WEBHOOK_AUTO_DISABLE_ENDPOINT_URL=false');
    $this->configBeforeBoot = ['outbound.webhooks.auto_disable.endpoint_url' => 'https://app.example/hooks/{endpoint}'];

    try {
        $this->refreshApplication();

        expect(config('outbound.webhooks.auto_disable.endpoint_url'))->toBe('https://app.example/hooks/{endpoint}');
    } finally {
        putenv('OUTBOUND_WEBHOOK_AUTO_DISABLE_ENDPOINT_URL');
        $this->configBeforeBoot = [];
        $this->refreshApplication();
    }
});

it('lets products opt out of the users foreign keys with a null table', function (): void {
    $this->configBeforeBoot = ['outbound.webhooks.users_table' => null];
    $this->refreshApplication();

    expect(config('outbound.webhooks.users_table'))->toBeNull();
});

it('refuses a retry preset that is not a known name', function (mixed $preset, string $message): void {
    config()->set('outbound.webhooks.retry_preset', $preset);
    $this->app->forgetInstance(WebhookDeliveryCoordinator::class);

    expect(fn () => $this->app->make(WebhookDeliveryCoordinator::class))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not a string' => [5, 'outbound.webhooks.retry_preset must be a string.'],
    'an unknown name' => ['fast', 'outbound.webhooks.retry_preset must be standard or short.'],
]);

it('uses the package default for a key a published config lacks', function (): void {
    config()->set('outbound.webhooks', Arr::except(config('outbound.webhooks'), 'max_secrets'));
    $this->app->forgetInstance(StandardWebhookSigner::class);
    $secrets = array_map(fn (): WebhookSecret => WebhookSecret::generate(), range(1, 5));

    expect(fn () => $this->app->make(StandardWebhookSigner::class)->sign('msg_1', 1_700_000_000, '{}', $secrets))
        ->toThrow(WebhookException::class, 'Too many webhook secrets.')
        ->and($this->app->make(StandardWebhookSigner::class)->sign('msg_1', 1_700_000_000, '{}', array_slice($secrets, 0, 4))->headers['webhook-signature'])->toContain('v1,');
});

it('gives the guard the metadata policy a product binds', function (): void {
    $this->app->instance(MetadataPolicyContract::class, new class implements MetadataPolicyContract
    {
        public function additionalMetadataHosts(): array
        {
            return ['meta.example'];
        }
    });
    $this->app->forgetInstance(RemoteUrlGuard::class);

    $result = $this->app->make(RemoteUrlGuard::class)->tryAuthorize('https://meta.example/latest');

    expect($result['ok'] ? null : $result['reason'])->toBe('Host is not allowed.');
});

it('refuses a malformed boolean or integer variable instead of casting it', function (string $variable, string $value, string $message): void {
    putenv("{$variable}={$value}");

    try {
        expect(fn () => $this->refreshApplication())->toThrow(InvalidArgumentException::class, $message);
    } finally {
        putenv($variable);
        $this->refreshApplication();
    }
})->with([
    // A cast would read this typo as true and open private hosts.
    'a boolean typo' => ['OUTBOUND_ALLOW_PRIVATE_HOSTS', 'flase', 'outbound.ssrf.allow_private_hosts must be true or false.'],
    'an integer in words' => ['OUTBOUND_WEBHOOK_TIMEOUT_SECONDS', 'ten', 'outbound.webhooks.timeout_seconds must be an integer.'],
    'a boolean as an integer' => ['OUTBOUND_WEBHOOK_TIMEOUT_SECONDS', 'true', 'outbound.webhooks.timeout_seconds must be an integer.'],
    'null as a boolean' => ['OUTBOUND_HTTPS_ONLY', 'null', 'outbound.ssrf.https_only must be true or false.'],
    'an empty boolean' => ['OUTBOUND_HTTPS_ONLY', '', 'outbound.ssrf.https_only must be true or false.'],
    'a zero-padded integer' => ['OUTBOUND_WEBHOOK_TIMEOUT_SECONDS', '030', 'outbound.webhooks.timeout_seconds must be an integer.'],
]);
