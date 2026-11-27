<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * The eraser looks a person up by these columns: each has its own index.
 * Every foreign key is covered by tests/Feature/ForeignKeyIndexTest.php.
 */

it('indexes every column the eraser looks a person up by', function (string $table, string $column): void {
    expect(array_column(Schema::getIndexes($table), 'columns'))->toContain([$column]);
})->with([
    ['webhook_endpoints', 'created_by'],
    ['webhook_deliveries', 'subject_public_id'],
    ['webhook_deliveries', 'next_attempt_triggered_by'],
    ['webhook_deliveries', 'claimed_triggered_by'],
    ['webhook_delivery_attempts', 'triggered_by'],
]);
