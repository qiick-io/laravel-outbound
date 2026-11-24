<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per webhook destination a product registers.
 *
 * The package owns the table name (standards review R1 and the 2026-11-22
 * standard-names decision): it is not configurable. A product that scopes
 * endpoints (organisation, project) adds its own foreign key column with its
 * own migration and names it in `outbound.webhooks.scope_column`; the package
 * defines no tenancy column and no morph (review R2).
 *
 * Nullable policy columns (`https_only`, `timeout_seconds`, `auto_disable`, …)
 * mean "use the config value", so a config change reaches every endpoint that
 * never overrode it. `public_id` is the only id that leaves the database, and
 * it is also the Standard Webhooks `v2a` endpoint id (review M15), so it never
 * changes.
 *
 * `headers` holds the endpoint's static request headers, which often carry an
 * `Authorization` token or an API key, so it is `text` under Laravel's
 * `encrypted:array` cast rather than `json`: ciphertext is not valid JSON.
 *
 * Until the package's 1.0.0, schema changes edit this create migration
 * (2026-11-22 package-migrations-before-stable decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        $usersTable = config('outbound.webhooks.users_table');

        Schema::create('webhook_endpoints', function (Blueprint $table) use ($usersTable): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 191)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('url', 2048);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 32)->nullable();
            $table->timestamp('failing_since')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('payload_version', 32)->default('1');
            $table->string('legacy_profile', 64)->nullable();
            $table->boolean('https_only')->nullable();
            $table->boolean('allow_private_hosts')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->unsignedSmallInteger('max_concurrency')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            // The throttle's state (standards review S7): the minute window the
            // claim counts attempts in, and since when an overload answer
            // (429, 502, 503, 504) has halved the concurrency.
            $table->timestamp('rate_window_started_at')->nullable();
            $table->unsignedInteger('rate_window_attempts')->default(0);
            $table->timestamp('throttled_at')->nullable();
            $table->string('retry_preset', 32)->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->boolean('auto_disable')->nullable();
            $table->timestamp('verified_at')->nullable();
            // JSON columns carry no literal default: MySQL and MariaDB reject one.
            $table->text('headers')->nullable();
            $table->json('metadata')->nullable();

            $createdBy = $table->foreignId('created_by')->nullable();

            if (is_string($usersTable) && $usersTable !== '') {
                $createdBy->constrained($usersTable)->nullOnDelete();
            }

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
