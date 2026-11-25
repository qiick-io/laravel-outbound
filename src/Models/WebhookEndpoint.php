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
 * Nullable policy attributes mean "use the config value". `headers` (static
 * request headers, often a token) is encrypted at rest and hidden from arrays
 * and JSON, like a secret. A product's scope
 * column (named in `outbound.webhooks.scope_column`) is an attribute the
 * product added with its own migration; the package defines none, and never
 * mass-assigns it.
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
 * @property CarbonImmutable|null $rate_window_started_at
 * @property int $rate_window_attempts
 * @property CarbonImmutable|null $throttled_at
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
     * The URL's host, lowercased, when it is a host name or an IP literal;
     * anything else (no host, spaces, punctuation) is "an unknown host". For
     * a notice about the endpoint: it never carries free text from the URL,
     * which mail clients could turn into a link.
     *
     * @api
     */
    public function displayHost(): string
    {
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));

        return preg_match('/^(?:[a-z0-9-]+(?:\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])$/D', $host) === 1 ? $host : 'an unknown host';
    }

    /**
     * What a product edits. The state the package manages (`is_active`, the
     * disable reason and the failure streak) is not mass-assignable. Nor are
     * the columns a request must never choose, which the product sets in its
     * own code: `allow_private_hosts` loosens the SSRF guard, `auto_disable`
     * can opt an endpoint out of auto-disable, `rate_limit_per_minute` sets
     * how fast the package sends, `created_by` is the user the disable email
     * goes to, and the product's scope column (in
     * `outbound.webhooks.scope_column`) decides the tenant: a request that
     * chose it could create an endpoint in another tenant.
     *
     * @return list<string>
     */
    protected function fillableAttributes(): array
    {
        return [
            'name',
            'description',
            'url',
            'payload_version',
            'legacy_profile',
            'https_only',
            'timeout_seconds',
            'max_concurrency',
            'retry_preset',
            'max_attempts',
            'headers',
            'metadata',
        ];
    }

    /**
     * @return list<string>
     */
    protected function privateAttributes(): array
    {
        return ['created_by', 'headers'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function databaseDefaults(): array
    {
        return [
            'is_active' => true,
            'consecutive_failures' => 0,
            'rate_window_attempts' => 0,
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
            'rate_window_started_at' => 'immutable_datetime',
            'throttled_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'https_only' => 'boolean',
            'allow_private_hosts' => 'boolean',
            'auto_disable' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'headers' => 'encrypted:array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
