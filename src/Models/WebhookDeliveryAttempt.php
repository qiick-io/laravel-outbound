<?php

declare(strict_types=1);

namespace Qiick\Outbound\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Qiick\Outbound\Enums\WebhookAttemptStatusEnum;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Models\Concerns\PackageModelTrait;

/**
 * One HTTP attempt of a delivery (`webhook_delivery_attempts`), append-only:
 * there is no `updated_at`.
 *
 * Dates are written with milliseconds, so `attempted_at` (`timestamp(3)`)
 * keeps attempts ordered within one second.
 *
 * @property int $id
 * @property string $public_id
 * @property int $delivery_id
 * @property int $attempt
 * @property WebhookAttemptTriggerEnum $trigger
 * @property int|null $triggered_by
 * @property WebhookAttemptStatusEnum $status
 * @property int $webhook_timestamp
 * @property string $signature_schemes
 * @property list<string>|null $secret_public_ids
 * @property string|null $pinned_address
 * @property int|null $http_status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $retry_after_seconds
 * @property array<string, string>|null $response_headers
 * @property string|null $response_excerpt
 * @property int|null $response_bytes
 * @property int|null $duration_ms
 * @property string|null $request_id
 * @property CarbonImmutable $attempted_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property-read WebhookDelivery $delivery
 *
 * @api
 */
class WebhookDeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    use PackageModelTrait;

    protected $table = 'webhook_delivery_attempts';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'delivery_id');
    }

    /**
     * None: attempts are append-only and written by the package alone.
     *
     * @return list<string>
     */
    protected function fillableAttributes(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function privateAttributes(): array
    {
        return ['delivery_id', 'triggered_by'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function databaseDefaults(): array
    {
        return [
            'trigger' => 'schedule',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => WebhookAttemptTriggerEnum::class,
            'status' => WebhookAttemptStatusEnum::class,
            'secret_public_ids' => 'array',
            'response_headers' => 'array',
            'attempted_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
