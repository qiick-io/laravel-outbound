<?php

declare(strict_types=1);

namespace Qiick\Outbound\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Qiick\Outbound\Enums\WebhookSignatureSchemeEnum;
use Qiick\Outbound\Models\Concerns\PackageModelTrait;

/**
 * One signing secret of an endpoint (`webhook_endpoint_secrets`).
 *
 * `secret` is encrypted at rest with the application key (2026-11-20 core
 * hardening: secrets at rest use the `encrypted` cast) and is hidden from
 * arrays and JSON: a product shows it once, on create and on rotate, and never
 * again.
 *
 * @property int $id
 * @property string $public_id
 * @property int $endpoint_id
 * @property WebhookSignatureSchemeEnum $scheme
 * @property string|null $secret
 * @property bool $is_legacy_raw
 * @property string|null $public_key
 * @property string|null $key_ref
 * @property bool $is_primary
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookEndpoint $endpoint
 *
 * @api
 */
class WebhookEndpointSecret extends Model
{
    use PackageModelTrait;

    protected $table = 'webhook_endpoint_secrets';

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * @return list<string>
     */
    protected function privateAttributes(): array
    {
        return ['endpoint_id', 'secret'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function databaseDefaults(): array
    {
        return [
            'scheme' => 'v1',
            'is_legacy_raw' => false,
            'is_primary' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheme' => WebhookSignatureSchemeEnum::class,
            'secret' => 'encrypted',
            'is_legacy_raw' => 'boolean',
            'is_primary' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
