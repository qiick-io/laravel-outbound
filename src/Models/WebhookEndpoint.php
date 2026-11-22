<?php

declare(strict_types=1);

namespace Qiick\Outbound\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Models\Concerns\PackageModelTrait;

/**
 * A webhook destination registered by a product (`webhook_endpoints`).
 *
 * Nullable policy attributes mean "use the config value". A product's scope
 * column (named in `outbound.webhooks.scope_column`) is an ordinary attribute
 * the product added with its own migration; the package defines none.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $name
 * @property string|null $description
 * @property string $url
 * @property bool $is_active
 * @property CarbonImmutable|null $disabled_at
 * @property WebhookEndpointDisabledReasonEnum|null $disabled_reason
 * @property CarbonImmutable|null $failing_since
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_failure_at
 * @property string $payload_version
 * @property string|null $legacy_profile
 * @property bool|null $https_only
 * @property bool|null $allow_private_hosts
 * @property int|null $timeout_seconds
 * @property int|null $max_concurrency
 * @property int|null $rate_limit_per_minute
 * @property string|null $retry_preset
 * @property int|null $max_attempts
 * @property bool|null $auto_disable
 * @property CarbonImmutable|null $verified_at
 * @property array<string, string>|null $headers
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 *
 * @api
 */
class WebhookEndpoint extends Model
{
    use PackageModelTrait;
    use SoftDeletes;

    protected $table = 'webhook_endpoints';

    /**
     * @return HasMany<WebhookEndpointSecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(WebhookEndpointSecret::class, 'endpoint_id');
    }

    /**
     * @return HasMany<WebhookSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(WebhookSubscription::class, 'endpoint_id');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    /**
     * @return list<string>
     */
    protected function privateAttributes(): array
    {
        return ['created_by'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function databaseDefaults(): array
    {
        return [
            'is_active' => true,
            'consecutive_failures' => 0,
            'payload_version' => '1',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'disabled_at' => 'immutable_datetime',
            'disabled_reason' => WebhookEndpointDisabledReasonEnum::class,
            'failing_since' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'https_only' => 'boolean',
            'allow_private_hosts' => 'boolean',
            'auto_disable' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'headers' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
