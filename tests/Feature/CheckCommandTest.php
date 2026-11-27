<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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

it('fails on a users table that does not exist, naming the key', function (): void {
    config()->set('outbound.webhooks.users_table', 'accounts');

    $this->artisan('outbound:check')
        ->expectsOutput('outbound.webhooks.users_table: table accounts is missing.')
        ->assertFailed();
});

it('fails on a cache that cannot hold a claim, naming the store', function (): void {
    // A database store without its table: the first claim would fail.
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.table', 'missing_cache');

    $this->artisan('outbound:check')
        ->expectsOutputToContain('cache.default: the webhook id dedupe store cannot claim an id')
        ->assertFailed();
});

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
        public function add($key, $value, $seconds): bool
        {
            return false;
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
