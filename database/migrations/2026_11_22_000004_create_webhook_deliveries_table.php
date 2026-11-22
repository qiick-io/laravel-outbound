<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per endpoint and event: the delivery log the fleet standard asks
 * for (standards review M9, M11, M12).
 *
 * `event_id` is sent as `webhook-id` on every attempt, a manual redelivery
 * included (review R7), so it is unique per endpoint and compares byte for
 * byte: on MySQL and MariaDB it uses utf8mb4_bin, for the same reason as
 * `webhook_subscriptions.event_type`.
 *
 * `payload` is the exact raw body under Laravel's `encrypted` cast, kept until
 * `payload_expires_at` (30 days by default) and then pruned to null;
 * `payload_hash` and `payload_bytes` stay for good (review R5). Retries are
 * driven by `next_attempt_at` through the (`status`, `next_attempt_at`)
 * index, not by queue delays, so a retry 24 h away survives queue loss.
 *
 * Deleting an endpoint with deliveries is refused: the log outlives a
 * soft-deleted endpoint until retention removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysqlFamily = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('webhook_deliveries', function (Blueprint $table) use ($isMysqlFamily): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->restrictOnDelete();
            $eventId = $table->string('event_id', 191)->index();

            if ($isMysqlFamily) {
                $eventId->charset('utf8mb4')->collation('utf8mb4_bin');
            }

            $table->string('event_type', 191);
            $table->timestamp('occurred_at');
            $table->string('payload_version', 32);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            $table->dateTime('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->longText('payload')->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('payload_bytes');
            $table->dateTime('payload_expires_at')->nullable()->index();
            $table->string('trigger', 16)->default('event');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['endpoint_id', 'event_id']);
            $table->index(['endpoint_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
