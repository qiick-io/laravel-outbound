<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One append-only row per HTTP attempt of a delivery: no `updated_at`.
 *
 * Every attempt carries its own `webhook-timestamp` and the public ids of the
 * secrets that signed it, never the secrets or the body. `response_headers`
 * holds an allowlist only (`content-type`, `retry-after`, `x-request-id`,
 * `date`). Responses may contain receiver data and are never stored as bodies
 * or excerpts (standards review R5 and S6).
 *
 * `attempted_at` keeps milliseconds so attempts stay ordered within a second
 * (review R9); every other past-event stamp follows Laravel's default.
 * Attempts go with their delivery when retention deletes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $usersTable = config('outbound.webhooks.users_table');

        Schema::create('webhook_delivery_attempts', function (Blueprint $table) use ($usersTable): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('trigger', 16)->default('schedule');

            // Indexed: PostgreSQL and SQLite do not index a foreign key, and a user deletion would scan the table.
            $triggeredBy = $table->foreignId('triggered_by')->nullable()->index();

            if (is_string($usersTable) && $usersTable !== '') {
                $triggeredBy->constrained($usersTable)->nullOnDelete();
            }

            $table->string('status', 16);
            $table->unsignedBigInteger('webhook_timestamp');
            $table->string('signature_schemes', 32);
            $table->json('secret_public_ids')->nullable();
            $table->string('pinned_address', 45)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->json('response_headers')->nullable();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('attempted_at', 3)->index();
            $table->dateTime('next_attempt_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['delivery_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
    }
};
