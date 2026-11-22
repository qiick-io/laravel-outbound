<?php

declare(strict_types=1);

namespace Qiick\Outbound\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Enums\WebhookDeliveryTriggerEnum;
use Qiick\Outbound\Models\Concerns\PackageModelTrait;

/**
 * One event for one endpoint (`webhook_deliveries`), with its retry state.
 *
 * `payload` is the exact raw body that every attempt signs, encrypted at rest
 * and hidden from arrays and JSON: bodies can carry personal data, and a
 * raw body never goes into logs or event payloads (standards review R5).
 *
 * @property int $id
 * @property string $public_id
 * @property int $endpoint_id
 * @property string $event_id
 * @property string $event_type
 * @property CarbonImmutable $occurred_at
 * @property string $payload_version
 * @property DeliveryStatus $status
 * @property int $attempts_count
 * @property int $max_attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $last_http_status
 * @property string|null $last_error
 * @property string|null $payload
 * @property string $payload_hash
 * @property int $payload_bytes
 * @property CarbonImmutable|null $payload_expires_at
 * @property WebhookDeliveryTriggerEnum $trigger
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookEndpoint $endpoint
 *
 * @api
 */
class WebhookDelivery extends Model
{
    use PackageModelTrait;

    protected $table = 'webhook_deliveries';

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id')->withTrashed();
    }

    /**
     * @return HasMany<WebhookDeliveryAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class, 'delivery_id');
    }

    /**
     * @return list<string>
     */
    protected function privateAttributes(): array
    {
        return ['endpoint_id', 'payload'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function databaseDefaults(): array
    {
        return [
            'status' => 'pending',
            'attempts_count' => 0,
            'trigger' => 'event',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'status' => DeliveryStatus::class,
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'payload' => 'encrypted',
            'payload_expires_at' => 'immutable_datetime',
            'trigger' => WebhookDeliveryTriggerEnum::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
