<?php

declare(strict_types=1);

namespace Qiick\Outbound\Tests\Support;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Qiick\Outbound\Laravel\OutboundServiceProvider;

/**
 * Boots a Testbench Laravel app with the package provider. CI runs the suite
 * on Laravel 12 and 13.
 */
abstract class PackageTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OutboundServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
