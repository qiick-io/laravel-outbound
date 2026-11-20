<?php

declare(strict_types=1);

namespace Qiick\Outbound\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\DnsHostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;

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
            return new CacheSeenWebhookIdStore($app->make(CacheRepository::class));
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/outbound.php' => config_path('outbound.php'),
            ], 'outbound-config');
        }
    }

    private static function configInt(ConfigRepository $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_int($value) ? $value : $default;
    }
}
