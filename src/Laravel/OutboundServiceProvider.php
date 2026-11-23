<?php

declare(strict_types=1);

namespace Qiick\Outbound\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Qiick\Outbound\Console\Commands\WorkWebhookDeliveriesCommand;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookRetryPresetEnum;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Retry\RetryPolicy;
use Qiick\Outbound\Retry\SystemRandomizer;
use Qiick\Outbound\Ssrf\DnsHostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\DatabaseWebhookSecretResolver;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;

/**
 * Auto-discovered Laravel service provider for qiick/laravel-outbound.
 *
 * @api
 */
final class OutboundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/outbound.php', 'outbound');

        $this->app->singleton(HostResolver::class, DnsHostResolver::class);

        $this->app->singleton(RemoteUrlGuard::class, function (Application $app): RemoteUrlGuard {
            $resolver = $app->make(HostResolver::class);
            $config = $app->make(ConfigRepository::class);

            return new RemoteUrlGuard(
                hostResolver: $resolver,
                allowPrivateHosts: (bool) $config->get('outbound.ssrf.allow_private_hosts', false),
                httpsOnly: (bool) $config->get('outbound.ssrf.https_only', false),
            );
        });

        $this->app->singleton(OutboundHttpLimits::class, function (Application $app): OutboundHttpLimits {
            $config = $app->make(ConfigRepository::class);

            return new OutboundHttpLimits(
                maxTimeoutSeconds: self::configInt($config, 'outbound.http.max_timeout_seconds', 60),
                maxConnectTimeoutSeconds: self::configInt($config, 'outbound.http.max_connect_timeout_seconds', 30),
                totalDeadlineSeconds: self::configInt($config, 'outbound.http.total_deadline_seconds', 30),
            );
        });

        // Private Factory — never the shared Http facade factory, so
        // Http::globalOptions(['handler' => ...]) cannot replace our curl wrap.
        $this->app->singleton(PinnedHttpClient::class, function (Application $app): PinnedHttpClient {
            return new PinnedHttpClient(
                new HttpFactory,
                $app->make(RemoteUrlGuard::class),
                $app->make(OutboundHttpLimits::class),
            );
        });

        $this->app->singleton(SeenWebhookIdStore::class, function (Application $app): SeenWebhookIdStore {
            // ArrayStore is atomic but process-local — allow only in the testing env.
            return new CacheSeenWebhookIdStore(
                $app->make(CacheRepository::class),
                allowArrayStore: $app->environment('testing'),
            );
        });

        $this->app->singleton(StandardWebhookSigner::class, function (Application $app): StandardWebhookSigner {
            $config = $app->make(ConfigRepository::class);

            return new StandardWebhookSigner(
                maxPayloadBytes: self::configInt($config, 'outbound.webhooks.max_payload_bytes', 1_048_576),
                maxSecrets: self::configInt($config, 'outbound.webhooks.max_secrets', 4),
            );
        });

        $this->app->singleton(StandardWebhookVerifier::class, function (Application $app): StandardWebhookVerifier {
            $config = $app->make(ConfigRepository::class);

            return new StandardWebhookVerifier(
                replayToleranceSeconds: self::configInt($config, 'outbound.webhooks.replay_tolerance_seconds', 300),
                maxPayloadBytes: self::configInt($config, 'outbound.webhooks.max_payload_bytes', 1_048_576),
                maxSignatures: self::configInt($config, 'outbound.webhooks.max_signatures', 8),
                maxSecrets: self::configInt($config, 'outbound.webhooks.max_secrets', 4),
                seenIds: $app->make(SeenWebhookIdStore::class),
            );
        });

        $this->app->singleton(WebhookSecretResolver::class, function (Application $app): WebhookSecretResolver {
            return new DatabaseWebhookSecretResolver(
                allowRawLegacySecret: $app->make(ConfigRepository::class)->get('outbound.webhooks.allow_raw_legacy_secret', false) === true,
            );
        });

        $this->app->singleton(DatabaseDeliveryStore::class, function (Application $app): DatabaseDeliveryStore {
            $scopeColumn = $app->make(ConfigRepository::class)->get('outbound.webhooks.scope_column');

            return new DatabaseDeliveryStore(
                scopeColumn: is_string($scopeColumn) && $scopeColumn !== '' ? $scopeColumn : null,
            );
        });

        $this->app->singleton(RetryPolicy::class, function (Application $app): RetryPolicy {
            $config = $app->make(ConfigRepository::class);

            return new RetryPolicy(
                new SystemRandomizer,
                jitterSeconds: self::configInt($config, 'outbound.webhooks.retry_jitter_seconds', 30),
                respectRetryAfter: $config->get('outbound.webhooks.respect_retry_after', true) !== false,
                retryAfterCapSeconds: self::configInt($config, 'outbound.webhooks.retry_after_cap_seconds', 86_400),
            );
        });

        $this->app->singleton(WebhookDeliveryCoordinator::class, function (Application $app): WebhookDeliveryCoordinator {
            $config = $app->make(ConfigRepository::class);
            $retryPreset = $config->get('outbound.webhooks.retry_preset');
            $maxResponseBytes = $config->get('outbound.http.max_response_bytes');

            return new WebhookDeliveryCoordinator(
                $app->make(DatabaseDeliveryStore::class),
                $app->make(WebhookSecretResolver::class),
                $app->make(StandardWebhookSigner::class),
                $app->make(PinnedHttpClient::class),
                $app->make(RetryPolicy::class),
                $app->make(OutboundHttpLimits::class),
                timeoutSeconds: self::configInt($config, 'outbound.webhooks.timeout_seconds', 15),
                connectTimeoutSeconds: self::configInt($config, 'outbound.http.connect_timeout_seconds', 3),
                defaultRetryPreset: WebhookRetryPresetEnum::tryFrom(is_string($retryPreset) ? $retryPreset : '') ?? WebhookRetryPresetEnum::Standard,
                defaultMaxConcurrency: self::configInt($config, 'outbound.webhooks.default_max_concurrency', 2),
                maxResponseBytes: is_int($maxResponseBytes) ? $maxResponseBytes : 1_048_576,
            );
        });

        $this->app->singleton(SecretRotator::class, function (Application $app): SecretRotator {
            $config = $app->make(ConfigRepository::class);

            return new SecretRotator(
                maxSecrets: self::configInt($config, 'outbound.webhooks.max_secrets', 4),
                defaultGraceSeconds: self::configInt($config, 'outbound.webhooks.secret_rotation_grace_seconds', 86_400),
            );
        });
    }

    /**
     * The webhook tables are mandatory, so the package loads its own
     * migrations unless `outbound.migrations.load` is off; a product that
     * turns it off publishes them instead (standards review R1).
     *
     * @throws WebhookException when `outbound.webhooks.max_secrets` is outside
     *                          1–4: the fixed limit is refused at boot, not at
     *                          the first rotation
     */
    public function boot(): void
    {
        $this->app->make(SecretRotator::class);

        $migrationsPath = __DIR__.'/../../database/migrations';

        if ($this->app->make(ConfigRepository::class)->get('outbound.migrations.load', true) !== false) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/outbound.php' => config_path('outbound.php'),
            ], 'outbound-config');

            $this->publishesMigrations([$migrationsPath => database_path('migrations')], 'outbound-migrations');

            $this->commands([WorkWebhookDeliveriesCommand::class]);
        }
    }

    private static function configInt(ConfigRepository $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_int($value) ? $value : $default;
    }
}
