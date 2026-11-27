<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * Fleet convention F2: `outbound:check` validates what the boot cannot (the
 * dedupe store, the tables) and exits non-zero, for the deploy pipeline. A
 * config value of the wrong type already stops the boot (F1).
 */

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
