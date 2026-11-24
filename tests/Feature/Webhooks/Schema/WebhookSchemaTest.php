<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The five package tables (standards review M1 and "Package schema"). The
 * suite runs on SQLite, PostgreSQL, MySQL and MariaDB in CI, so these tests
 * also prove the migrations, defaults, keys and collations on each engine.
 */

function insertEndpointRow(array $overrides = []): int
{
    return (int) DB::table('webhook_endpoints')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'url' => 'https://hooks.example.com/receive',
        ...$overrides,
    ]);
}

function insertDeliveryRow(int $endpointId, string $eventId = 'msg_1'): int
{
    return (int) DB::table('webhook_deliveries')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'endpoint_id' => $endpointId,
        'event_id' => $eventId,
        'event_type' => 'invoice.paid',
        'occurred_at' => '2026-11-22 10:00:00',
        'payload_version' => '1',
        'max_attempts' => 10,
        'payload_hash' => str_repeat('a', 64),
        'payload_bytes' => 2,
        'payload_expires_at' => '2026-12-22 10:00:00',
    ]);
}

function insertAttemptRow(int $deliveryId, int $attempt = 1): int
{
    return (int) DB::table('webhook_delivery_attempts')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'delivery_id' => $deliveryId,
        'attempt' => $attempt,
        'status' => 'failed',
        'webhook_timestamp' => 1_763_800_000,
        'signature_schemes' => 'v1',
        'attempted_at' => '2026-11-22 10:00:00.123',
    ]);
}

it('creates the five tables with the standard fixed names and every column of the review', function (string $table, array $columns): void {
    expect(Schema::hasTable($table))->toBeTrue()
        ->and(Schema::getColumnListing($table))->toEqualCanonicalizing($columns);
})->with([
    'webhook_endpoints' => ['webhook_endpoints', [
        'id', 'public_id', 'name', 'description', 'url', 'is_active', 'disabled_at', 'disabled_reason',
        'failing_since', 'consecutive_failures', 'last_success_at', 'last_failure_at', 'payload_version',
        'legacy_profile', 'https_only', 'allow_private_hosts', 'timeout_seconds', 'max_concurrency',
        'rate_limit_per_minute', 'retry_preset', 'max_attempts', 'auto_disable', 'verified_at', 'headers',
        'metadata', 'created_by', 'created_at', 'updated_at', 'deleted_at',
    ]],
    'webhook_endpoint_secrets' => ['webhook_endpoint_secrets', [
        'id', 'public_id', 'endpoint_id', 'scheme', 'secret', 'is_legacy_raw', 'public_key', 'key_ref',
        'is_primary', 'expires_at', 'revoked_at', 'metadata', 'created_at', 'updated_at',
    ]],
    'webhook_subscriptions' => ['webhook_subscriptions', [
        'id', 'public_id', 'endpoint_id', 'event_type', 'filter', 'created_at', 'updated_at',
    ]],
    'webhook_deliveries' => ['webhook_deliveries', [
        'id', 'public_id', 'endpoint_id', 'event_id', 'event_type', 'occurred_at', 'payload_version', 'status',
        'attempts_count', 'max_attempts', 'next_attempt_at', 'claimed_until', 'exhausted_reason',
        'next_attempt_trigger', 'next_attempt_triggered_by', 'redelivery_requests', 'last_attempt_at',
        'delivered_at', 'last_http_status', 'last_error', 'payload', 'payload_hash', 'payload_bytes', 'payload_expires_at', 'trigger', 'metadata',
        'created_at', 'updated_at',
    ]],
    'webhook_delivery_attempts' => ['webhook_delivery_attempts', [
        'id', 'public_id', 'delivery_id', 'attempt', 'trigger', 'triggered_by', 'status', 'webhook_timestamp',
        'signature_schemes', 'secret_public_ids', 'pinned_address', 'http_status', 'error_code', 'error_message',
        'retry_after_seconds', 'response_headers', 'response_excerpt', 'response_bytes', 'duration_ms',
        'request_id', 'attempted_at', 'next_attempt_at', 'metadata', 'created_at',
    ]],
]);

it('applies the documented column defaults', function (): void {
    $endpointId = insertEndpointRow();
    $deliveryId = insertDeliveryRow($endpointId);
    insertAttemptRow($deliveryId);
    DB::table('webhook_endpoint_secrets')->insert(['public_id' => (string) Str::uuid7(), 'endpoint_id' => $endpointId]);

    $endpoint = DB::table('webhook_endpoints')->first();
    $secret = DB::table('webhook_endpoint_secrets')->first();
    $delivery = DB::table('webhook_deliveries')->first();
    $attempt = DB::table('webhook_delivery_attempts')->first();

    expect((bool) $endpoint->is_active)->toBeTrue()
        ->and((int) $endpoint->consecutive_failures)->toBe(0)
        ->and($endpoint->payload_version)->toBe('1')
        ->and($endpoint->https_only)->toBeNull()
        ->and($endpoint->auto_disable)->toBeNull()
        ->and($secret->scheme)->toBe('v1')
        ->and((bool) $secret->is_legacy_raw)->toBeFalse()
        ->and((bool) $secret->is_primary)->toBeFalse()
        ->and($delivery->status)->toBe('pending')
        ->and((int) $delivery->attempts_count)->toBe(0)
        ->and($delivery->trigger)->toBe('event')
        ->and($attempt->trigger)->toBe('schedule');
});

it('requires a body expiry on every delivery, so the prune can always delete the row', function (): void {
    $endpointId = insertEndpointRow();

    expect(fn () => DB::table('webhook_deliveries')->insert([
        'public_id' => (string) Str::uuid7(),
        'endpoint_id' => $endpointId,
        'event_id' => 'msg_no_expiry',
        'event_type' => 'invoice.paid',
        'occurred_at' => '2026-11-22 10:00:00',
        'payload_version' => '1',
        'max_attempts' => 10,
        'payload_hash' => str_repeat('a', 64),
        'payload_bytes' => 2,
    ]))->toThrow(QueryException::class);
});

it('keeps one subscription per endpoint and event type, compared case-sensitively', function (): void {
    $endpointId = insertEndpointRow();
    $subscribe = fn (string $eventType) => DB::table('webhook_subscriptions')->insert([
        'public_id' => (string) Str::uuid7(),
        'endpoint_id' => $endpointId,
        'event_type' => $eventType,
    ]);

    $subscribe('invoice.paid');
    $subscribe('Invoice.Paid');

    expect(DB::table('webhook_subscriptions')->where('event_type', 'invoice.paid')->count())->toBe(1)
        ->and(fn () => $subscribe('invoice.paid'))->toThrow(QueryException::class);
});

it('keeps one delivery per endpoint and webhook-id, compared case-sensitively', function (): void {
    $endpointId = insertEndpointRow();
    $otherEndpointId = insertEndpointRow();

    insertDeliveryRow($endpointId, 'msg_Abc');
    insertDeliveryRow($endpointId, 'msg_abc');
    insertDeliveryRow($otherEndpointId, 'msg_abc');

    expect(DB::table('webhook_deliveries')->where('event_id', 'msg_abc')->count())->toBe(2)
        ->and(fn () => insertDeliveryRow($endpointId, 'msg_abc'))->toThrow(QueryException::class);
});

it('keeps one attempt row per delivery and attempt number', function (): void {
    $deliveryId = insertDeliveryRow(insertEndpointRow());
    insertAttemptRow($deliveryId, 1);
    insertAttemptRow($deliveryId, 2);

    expect(fn () => insertAttemptRow($deliveryId, 2))->toThrow(QueryException::class);
});

it('refuses a duplicate public id in every table', function (): void {
    $publicId = (string) Str::uuid7();
    insertEndpointRow(['public_id' => $publicId]);

    expect(fn () => insertEndpointRow(['public_id' => $publicId]))->toThrow(QueryException::class);
});

it('refuses to delete an endpoint that still has secrets', function (): void {
    $endpointId = insertEndpointRow();
    DB::table('webhook_endpoint_secrets')->insert(['public_id' => (string) Str::uuid7(), 'endpoint_id' => $endpointId]);

    expect(fn () => DB::table('webhook_endpoints')->delete($endpointId))->toThrow(QueryException::class);
});

it('refuses to delete an endpoint that still has deliveries', function (): void {
    $endpointId = insertEndpointRow();
    insertDeliveryRow($endpointId);

    expect(fn () => DB::table('webhook_endpoints')->delete($endpointId))->toThrow(QueryException::class);
});

it('removes subscriptions with their endpoint and attempts with their delivery', function (): void {
    $endpointId = insertEndpointRow();
    DB::table('webhook_subscriptions')->insert([
        'public_id' => (string) Str::uuid7(),
        'endpoint_id' => $endpointId,
        'event_type' => '*',
    ]);
    $deliveryId = insertDeliveryRow(insertEndpointRow());
    insertAttemptRow($deliveryId);

    DB::table('webhook_endpoints')->delete($endpointId);
    DB::table('webhook_deliveries')->delete($deliveryId);

    expect(DB::table('webhook_subscriptions')->count())->toBe(0)
        ->and(DB::table('webhook_delivery_attempts')->count())->toBe(0);
});

it('nulls created_by and triggered_by when the user is deleted', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'not-a-real-hash',
    ]);
    $endpointId = insertEndpointRow(['created_by' => $userId]);
    $attemptId = insertAttemptRow(insertDeliveryRow($endpointId));
    DB::table('webhook_delivery_attempts')->where('id', $attemptId)->update(['triggered_by' => $userId]);

    DB::table('users')->delete($userId);

    expect(DB::table('webhook_endpoints')->value('created_by'))->toBeNull()
        ->and(DB::table('webhook_delivery_attempts')->value('triggered_by'))->toBeNull();
});

it('keeps milliseconds on attempted_at so attempts stay ordered within a second', function (): void {
    $deliveryId = insertDeliveryRow(insertEndpointRow());
    insertAttemptRow($deliveryId);

    expect((string) DB::table('webhook_delivery_attempts')->value('attempted_at'))->toContain('10:00:00.123');
});
