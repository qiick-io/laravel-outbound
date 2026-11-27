<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fleet convention F2: `outbound:check` validates what the boot cannot (the
 * dedupe store, the tables) and exits non-zero, for the deploy pipeline. A
 * config value of the wrong type already stops the boot (F1).
 */

beforeEach(function (): void {
    // The test database migrates on first use: before a test changes the
    // environment (production asks to confirm) or the users table the
    // migrations reference.
    expect(Schema::hasTable('webhook_endpoints'))->toBeTrue();
});

it('passes on a migrated database with a usable dedupe store', function (): void {
    $this->artisan('outbound:check')
        ->expectsOutput('outbound: configuration and environment are ready.')
        ->assertSuccessful();
});

it('gives the probe a short TTL and releases the claim on success', function (): void {
    $cache = new class extends ArrayStore
    {
        public array $seenTtls = [];

        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            $this->seenTtls[] = $seconds;

            return parent::lock($name, $seconds, $owner);
        }
    };
    $this->app->instance(CacheRepository::class, new Repository($cache));
    app(Schedule::class)->call(static fn () => null);

    $this->artisan('outbound:check')->assertSuccessful();

    expect($cache->seenTtls[0])->toBe(60)
        ->and($cache->locks)->toBe([]);
});

it('fails on a dedupe store that is not shared between processes', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('cache.default', 'array');

    $this->artisan('outbound:check')
        ->expectsOutputToContain('cache.default: CacheSeenWebhookIdStore refuses ArrayStore outside tests')
        ->assertFailed();
});

it('fails on a missing webhook table, naming it', function (): void {
    Schema::drop('webhook_delivery_attempts');

    $this->artisan('outbound:check')
        ->expectsOutput('database: table webhook_delivery_attempts is missing (run the migrations).')
        ->assertFailed();
});

it('inspects every package model table on its connection', function (string $table): void {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.outbound_probe', ['driver' => 'sqlite', 'database' => ':memory:']);
    $schema = Schema::connection('outbound_probe');

    try {
        foreach (['users', 'webhook_endpoints', 'webhook_endpoint_secrets', 'webhook_subscriptions', 'webhook_deliveries', 'webhook_delivery_attempts'] as $expectedTable) {
            if ($expectedTable !== $table) {
                $schema->create($expectedTable, static fn (Blueprint $blueprint) => $blueprint->id());
            }
        }

        DB::setDefaultConnection('outbound_probe');

        $this->artisan('outbound:check')
            ->expectsOutput("database: table {$table} is missing (run the migrations).")
            ->assertFailed();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('outbound_probe');
    }
})->with(['webhook_endpoints', 'webhook_endpoint_secrets', 'webhook_subscriptions', 'webhook_deliveries']);

it('fails on a users table that does not exist, naming the key', function (): void {
    config()->set('outbound.webhooks.users_table', 'accounts');

    $this->artisan('outbound:check')
        ->expectsOutput('outbound.webhooks.users_table: table accounts is missing.')
        ->assertFailed();
});

it('fails on a database cache missing a required table', function (string $configKey): void {
    config()->set('cache.default', 'database');
    config()->set($configKey, 'missing_cache');

    $this->artisan('outbound:check')
        ->expectsOutput('cache.default: the webhook id dedupe store cannot claim an id ('.QueryException::class.').')
        ->assertFailed();
})->with(['cache.stores.database.table', 'cache.stores.database.lock_table']);

it('skips the users table when the product leaves it empty, as the migrations do', function (?string $usersTable): void {
    config()->set('outbound.webhooks.users_table', $usersTable);

    $this->artisan('outbound:check')->assertSuccessful();
})->with(['empty' => [''], 'null' => [null]]);

it('fails deploy checks on a scheduled deprecated outbound command', function (string $legacy, string $replacement): void {
    config()->set('outbound.webhooks.schedule', false);
    app(Schedule::class)->command($legacy);

    $this->artisan('outbound:check')
        ->expectsOutput("schedule: replace {$legacy} with {$replacement}.")
        ->assertFailed();
})->with([
    'work' => ['outbound:webhooks:work', 'outbound:work-deliveries'],
    'prune' => ['outbound:webhooks:prune', 'outbound:prune-deliveries'],
]);

it('fails on a product prune schedule while the package owns the schedule', function (string $command): void {
    app(Schedule::class)->command($command);

    $this->artisan('outbound:check')
        ->expectsOutput("schedule: remove {$command}; the package schedules outbound:prune-deliveries.")
        ->assertFailed();
})->with(['outbound:webhooks:prune', 'outbound:prune-deliveries']);

it('fails when the cache reports that it did not record the probe', function (): void {
    $this->app->instance(CacheRepository::class, new Repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function acquire(): bool
                {
                    return false;
                }
            };
        }
    }));

    $this->artisan('outbound:check')
        ->expectsOutput('cache.default: the webhook id dedupe store did not record the probe claim.')
        ->assertFailed();
});

it('reports a cache resolution exception without printing its message', function (): void {
    $this->app->bind(CacheRepository::class, static fn () => throw new RuntimeException('fixture cache error'));

    $this->artisan('outbound:check')
        ->expectsOutput('cache.default: could not resolve the webhook id dedupe store (RuntimeException).')
        ->doesntExpectOutputToContain('fixture cache error')
        ->assertFailed();
});
