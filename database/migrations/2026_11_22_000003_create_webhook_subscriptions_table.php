<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The event types an endpoint receives: one row per endpoint and type, or
 * `*` for every type (standards review M2). Filtering happens on the
 * producing side, so an endpoint never receives an event it did not choose.
 *
 * Event types are case-sensitive names (`invoice.paid` is not `Invoice.Paid`).
 * The usual collations of the MySQL family compare case-insensitively, which
 * would let the unique key refuse a distinct type and the lookup match the
 * wrong one, so on MySQL and MariaDB `event_type` uses utf8mb4_bin. A
 * collation, not a binary type: VARBINARY becomes `bytea` on PostgreSQL.
 *
 * `filter` is reserved for payload filters (review L4) and unused in 1.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysqlFamily = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('webhook_subscriptions', function (Blueprint $table) use ($isMysqlFamily): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $eventType = $table->string('event_type', 191)->index();

            if ($isMysqlFamily) {
                $eventType->charset('utf8mb4')->collation('utf8mb4_bin');
            }

            $table->json('filter')->nullable();
            $table->timestamps();

            $table->unique(['endpoint_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
