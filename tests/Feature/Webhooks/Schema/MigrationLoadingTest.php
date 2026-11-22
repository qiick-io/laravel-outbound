<?php

declare(strict_types=1);

/*
 * Mandatory tables are loaded by the package itself, as qiick/laravel-oidc
 * does; a product may turn that off and publish them (standards review R1).
 */

$packageMigrationsPath = realpath(__DIR__.'/../../../../database/migrations');

it('loads the package migrations by default', function () use ($packageMigrationsPath): void {
    $loadedPaths = array_map(realpath(...), $this->app->make('migrator')->paths());

    expect($loadedPaths)->toContain($packageMigrationsPath);
});

it('does not load the package migrations when outbound.migrations.load is off', function () use ($packageMigrationsPath): void {
    putenv('OUTBOUND_MIGRATIONS_LOAD=false');

    try {
        $this->refreshApplication();
        $loadedPaths = array_map(realpath(...), $this->app->make('migrator')->paths());
    } finally {
        putenv('OUTBOUND_MIGRATIONS_LOAD');
    }

    expect(config('outbound.migrations.load'))->toBeFalse()
        ->and($loadedPaths)->not->toContain($packageMigrationsPath);
});

it('publishes the migrations under the outbound-migrations tag', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'outbound-migrations'])->assertSuccessful();

    $publishedMigrations = glob(database_path('migrations/*_create_webhook_*_table.php')) ?: [];

    foreach ($publishedMigrations as $publishedMigration) {
        unlink($publishedMigration);
    }

    expect(array_map(
        static fn (string $path): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)) ?? '',
        $publishedMigrations,
    ))->toEqualCanonicalizing([
        'create_webhook_endpoints_table.php',
        'create_webhook_endpoint_secrets_table.php',
        'create_webhook_subscriptions_table.php',
        'create_webhook_deliveries_table.php',
        'create_webhook_delivery_attempts_table.php',
    ]);
});
