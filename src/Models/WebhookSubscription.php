<?php

declare(strict_types=1);

namespace Qiick\Outbound\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Qiick\Outbound\Models\Concerns\PackageModelTrait;

/**
 * One event type an endpoint receives (`webhook_subscriptions`): an exact
 * type such as `invoice.paid`, or `*` for every type.
 *
 * @property int $id
 * @property string $public_id
 * @property int $endpoint_id
 * @property string $event_type
 * @property array<string, mixed>|null $filter
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookEndpoint $endpoint
 *
 * @api
 */
class WebhookSubscription extends Model
{
    use PackageModelTrait;

    /**
     * The event type that subscribes an endpoint to every type.
     */
    public const string ALL_EVENT_TYPES = '*';

    protected $table = 'webhook_subscriptions';

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
        return ['endpoint_id'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function databaseDefaults(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
