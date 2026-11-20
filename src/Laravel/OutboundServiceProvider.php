<?php

declare(strict_types=1);

namespace Qiick\Outbound\Laravel;

use Illuminate\Support\ServiceProvider;

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
