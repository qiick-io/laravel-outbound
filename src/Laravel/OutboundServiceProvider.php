<?php

declare(strict_types=1);

namespace Qiick\Outbound\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Qiick\Outbound\Contracts\HostResolver;
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
