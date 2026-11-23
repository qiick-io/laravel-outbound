<?php

declare(strict_types=1);

namespace Qiick\Outbound\Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase;
use Qiick\Outbound\Laravel\OutboundServiceProvider;

/**
 * Boots a Testbench Laravel app with the package provider. CI runs the suite
 * on Laravel 12 and 13.
 *
 * The database is in-memory SQLite unless the process environment names
 * another connection: CI runs the suite on PostgreSQL, MySQL and MariaDB as
 * well, because the package ships migrations (1.0 checklist item 6). The
 * Laravel default migrations run too, so `users` exists for `created_by`.
 */
#[WithMigration]
abstract class PackageTestCase extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Config applied before the providers boot, for a test that sets it and
     * then calls refreshApplication(). Per instance, so it never leaks into
     * another test the way a process-wide putenv() could.
     *
     * @var array<string, mixed>
     */
    protected array $configBeforeBoot = [];

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
        $app['config']->set('database.default', getenv('DB_CONNECTION') ?: 'testing');
        // Products run with foreign keys on; the in-memory connection must too.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        $app['config']->set($this->configBeforeBoot);
    }
}
