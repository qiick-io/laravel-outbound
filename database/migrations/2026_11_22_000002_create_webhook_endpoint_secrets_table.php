<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The signing secrets of an endpoint: a table, not a `previous_secret`
 * column, because a rotation can need up to `outbound.webhooks.max_secrets`
 * (4) secrets at once (standards review R9, the maintainer's decision Q1 of 2026-11-22).
 *
 * The signing set is the primary secret plus the non-revoked secrets whose
 * `expires_at` is still in the future. `secret` holds the `whsec_` string
 * under Laravel's `encrypted` cast; it is null for a future `v2a` key, which
 * keeps only its public half (`public_key`) or a KMS reference (`key_ref`),
 * reserved now so `v2a` needs no migration after 1.0 (review M15).
 *
 * "One primary per endpoint" and the `max_secrets` cap are enforced in code,
 * under a lock on the endpoint row, not by the schema: MySQL and MariaDB have
 * no partial unique index, and the cap is configuration.
 *
 * Deleting an endpoint that still has secrets is refused: an endpoint is
 * soft-deleted, and its secrets are revoked, never silently dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoint_secrets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->restrictOnDelete();
            $table->string('scheme', 8)->default('v1');
            $table->text('secret')->nullable();
            $table->boolean('is_legacy_raw')->default(false);
            $table->text('public_key')->nullable();
            $table->string('key_ref', 191)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['endpoint_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoint_secrets');
    }
};
