<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Erasure\OutboundEraser;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookMessage;
use Qiick\Standards\Erasure\Support\ErasureFixture;
use Qiick\Standards\Erasure\Support\ErasurePerson;

/**
 * Seeds every outbound store with one person's data through the package's own
 * code: an endpoint they created (with its secret and subscription), a pending
 * delivery about them and a delivered one about one of their API keys, both
 * queued with `subject_public_id` and carrying their identifiers in the
 * encrypted payload, and a redelivery and an attempt they triggered.
 *
 * Outbound writes no cache, disk or further connection with personal data:
 * the seen-webhook-id cache of the verifier holds receivers' webhook ids.
 *
 * `payload_hash` is classified `other`: the SHA-256 of a body that also holds
 * the event id and timestamp, kept as delivery evidence (standards review R5),
 * never the seeded value itself. Endpoint configuration (`name`, `url`,
 * `headers`, `metadata`) is the organisation's, and `metadata` columns are
 * the product's to fill and to erase.
 */
return new class implements ErasureFixture
{
    public function packageName(): string
    {
        return 'qiick/laravel-outbound';
    }

    public function eraserClass(): string
    {
        return OutboundEraser::class;
    }

    public function connectionName(): ?string
    {
        return null;
    }

    public function tables(): array
    {
        return [
            'webhook_endpoints' => [
                ...self::other(['id', 'public_id', 'name', 'description', 'url', 'is_active', 'disabled_at', 'disabled_reason', 'failing_since',
                    'consecutive_failures', 'last_success_at', 'last_failure_at', 'payload_version', 'legacy_profile', 'https_only',
                    'allow_private_hosts', 'timeout_seconds', 'max_concurrency', 'rate_limit_per_minute', 'rate_window_started_at',
                    'rate_window_attempts', 'throttled_at', 'retry_preset', 'max_attempts', 'auto_disable', 'verified_at', 'headers',
                    'metadata', 'created_at', 'updated_at', 'deleted_at']),
                'created_by' => 'personal',
            ],
            'webhook_endpoint_secrets' => self::other(['id', 'public_id', 'endpoint_id', 'scheme', 'secret', 'is_legacy_raw', 'public_key',
                'key_ref', 'is_primary', 'expires_at', 'revoked_at', 'metadata', 'created_at', 'updated_at']),
            'webhook_subscriptions' => self::other(['id', 'public_id', 'endpoint_id', 'event_type', 'filter', 'created_at', 'updated_at']),
            'webhook_deliveries' => [
                ...self::other(['id', 'public_id', 'endpoint_id', 'event_id', 'event_type', 'occurred_at', 'payload_version', 'status',
                    'attempts_count', 'max_attempts', 'next_attempt_at', 'claimed_until', 'exhausted_reason', 'next_attempt_trigger',
                    'redelivery_requests', 'last_attempt_at', 'delivered_at', 'last_http_status', 'last_error', 'payload_hash',
                    'payload_bytes', 'payload_expires_at', 'trigger', 'metadata', 'created_at', 'updated_at']),
                'next_attempt_triggered_by' => 'personal',
                'payload' => 'personal',
                'subject_public_id' => 'personal',
            ],
            'webhook_delivery_attempts' => [
                ...self::other(['id', 'public_id', 'delivery_id', 'attempt', 'trigger', 'status', 'webhook_timestamp', 'signature_schemes',
                    'secret_public_ids', 'pinned_address', 'http_status', 'error_code', 'error_message', 'retry_after_seconds',
                    'response_headers', 'response_excerpt', 'response_bytes', 'duration_ms', 'request_id', 'attempted_at',
                    'next_attempt_at', 'metadata', 'created_at']),
                'triggered_by' => 'personal',
            ],
        ];
    }

    public function retainedCleartextColumns(): array
    {
        return [];
    }

    public function diskPaths(ErasurePerson $person): array
    {
        return [];
    }

    public function additionalConnections(): array
    {
        return [];
    }

    public function makePerson(): ErasurePerson
    {
        $userId = max(100_001, (int) DB::table('users')->max('id') + 1);
        $person = ErasurePerson::fresh((string) $userId);
        DB::table('users')->insert(['id' => $userId, 'name' => $person->identifiers[2], 'email' => $person->identifiers[0], 'password' => 'x']);

        return $person;
    }

    public function seed(ErasurePerson $person, ?string $organisationId): void
    {
        [$email, $username, $name] = $person->identifiers;
        $userId = (int) $person->userId;
        // One event type per person, so a dispatch reaches only their endpoint;
        // random ids, so the user key never sits in an `other` column.
        $eventType = 'person.updated.'.Str::lower(Str::random(12));
        [$pendingMessageId, $deliveredMessageId] = ['msg_'.Str::random(24), 'msg_'.Str::random(24)];

        $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive', 'created_by' => $userId]);
        $endpoint->save();
        $endpoint->subscriptions()->create(['event_type' => $eventType]);
        app(SecretRotator::class)->issue($endpoint);

        $coordinator = app(WebhookDeliveryCoordinator::class);
        $data = ['email' => $email, 'username' => $username, 'name' => $name, 'seen_from' => $person->otherPersonalValues];
        $coordinator->dispatch(new WebhookMessage($pendingMessageId, $eventType, new DateTimeImmutable, $data), subjectPublicId: $person->userPublicId);
        $coordinator->dispatch(new WebhookMessage($deliveredMessageId, $eventType, new DateTimeImmutable, $data), subjectPublicId: $person->publicIds[0]);

        $delivered = WebhookDelivery::query()->where('event_id', $deliveredMessageId)->sole();
        $delivered->forceFill(['status' => DeliveryStatus::Delivered, 'next_attempt_at' => null, 'delivered_at' => CarbonImmutable::now(), 'next_attempt_triggered_by' => $userId])->save();
        $delivered->attempts()->make()->forceFill([
            'attempt' => 1,
            'trigger' => 'manual',
            'triggered_by' => $userId,
            'status' => 'delivered',
            'webhook_timestamp' => CarbonImmutable::now()->getTimestamp(),
            'signature_schemes' => 'v1',
            'http_status' => 204,
            'attempted_at' => CarbonImmutable::now(),
        ])->save();
    }

    public function supportsOrganisationScope(): bool
    {
        return false;
    }

    public function organisationScopedTables(): array
    {
        return [];
    }

    public function cacheKeys(ErasurePerson $person): array
    {
        return [];
    }

    public function call(Closure $erase): object
    {
        return $erase();
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, 'other'>
     */
    private static function other(array $columns): array
    {
        return array_fill_keys($columns, 'other');
    }
};
