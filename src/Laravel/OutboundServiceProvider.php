<?php

declare(strict_types=1);

namespace Qiick\Outbound\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\DnsHostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;

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

        $this->app->singleton(PinnedHttpClient::class, function (Application $app): PinnedHttpClient {
            return new PinnedHttpClient(
                $app->make(HttpFactory::class),
                $app->make(RemoteUrlGuard::class),
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
}
