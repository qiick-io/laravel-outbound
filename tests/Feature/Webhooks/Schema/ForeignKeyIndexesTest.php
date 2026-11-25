<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * Every foreign key on a table the package migrates has an index that starts
 * with its columns. PostgreSQL and SQLite do not create one for a foreign key
 * (MySQL and MariaDB do), and without it a delete on the referenced table, a
 * user above all, scans the whole child table. CI runs this on SQLite,
 * PostgreSQL, MySQL and MariaDB.
 */

it('indexes every foreign key of the package tables', function (): void {
    $unindexedForeignKeys = [];

    foreach (['webhook_endpoints', 'webhook_endpoint_secrets', 'webhook_subscriptions', 'webhook_deliveries', 'webhook_delivery_attempts'] as $table) {
        $indexColumnLists = array_column(Schema::getIndexes($table), 'columns');

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $isIndexed = array_filter(
                $indexColumnLists,
                static fn (array $indexColumns): bool => array_slice($indexColumns, 0, count($foreignKey['columns'])) === $foreignKey['columns'],
            ) !== [];

            if (! $isIndexed) {
                $unindexedForeignKeys[] = $table.'.'.implode(',', $foreignKey['columns']);
            }
        }
    }

    expect($unindexedForeignKeys)->toBe([]);
});
