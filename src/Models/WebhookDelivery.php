<?php

declare(strict_types=1);

namespace Qiick\Outbound\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PDOException;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryConcurrencyException;
use Qiick\Outbound\Delivery\WebhookDeliveryStorageException;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Enums\WebhookDeliveryTriggerEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Models\Concerns\PackageModelTrait;

/**
 * One event for one endpoint (`webhook_deliveries`), with its retry state.
 *
 * `payload` is the exact raw body that every attempt signs, encrypted at rest
 * and hidden from arrays and JSON, like its hash: bodies can carry personal
 * data, and a raw body never goes into logs or event payloads (standards
 * review R5). The body, its hash and its length are set together, only
 * through {@see usePayload()}; the retry state is the package's to write.
 *
 * `payload_hash` is the plain SHA-256 of the body (review R5), used to
 * deduplicate and to verify a body after it is pruned; the eraser clears it
 * on a delivery about the person, since it derives from their data. A short body can be
 * guessed from it, so it is hidden from arrays, and a failed {@see save()}
 * is rethrown without its bindings. Writes through the query builder
 * (`insert()`, `update()`, `upsert()`) are not wrapped, and query recorders
 * (Telescope, Debugbar, Flare's query bindings, `DB::listen`) see the hash of
 * every successful write.
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
 * @property CarbonImmutable|null $claimed_until
 * @property WebhookExhaustedReasonEnum|null $exhausted_reason
 * @property WebhookAttemptTriggerEnum|null $next_attempt_trigger
 * @property int|null $next_attempt_triggered_by
 * @property int $redelivery_requests
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $last_http_status
 * @property string|null $last_error
 * @property string|null $payload
 * @property string|null $payload_hash
 * @property int $payload_bytes
 * @property CarbonImmutable $payload_expires_at
 * @property WebhookDeliveryTriggerEnum $trigger
 * @property string|null $subject_public_id
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
     * A failed write is rethrown without its bindings, so `payload_hash`
     * never reaches an exception message or a log. The write runs in its own
     * transaction, a savepoint inside the caller's: on PostgreSQL a refused
     * duplicate would otherwise abort the product's whole transaction.
     *
     * @param  array{touch?: bool|null}  $options
     *
     * @throws WebhookDeliveryStorageException when the row cannot be written
     * @throws WebhookDeliveryConcurrencyException on a deadlock or serialization
     *                                             failure, which a product
     *                                             transaction may retry
     */
    public function save(array $options = []): bool
    {
        try {
            /** @var bool $isSaved Laravel 12 types transaction() as mixed. */
            $isSaved = $this->getConnection()->transaction(fn (): bool => parent::save($options));

            return $isSaved;
        } catch (PDOException $databaseException) {
            // QueryException and, inside a transaction, DeadlockException.
            throw WebhookDeliveryStorageException::withoutBindings($databaseException);
        }
    }

    /**
     * Store the exact raw body with its SHA-256 hash and its length in bytes,
     * so the three always agree. Does not save.
     */
    public function usePayload(#[\SensitiveParameter] string $rawBody): static
    {
        return $this->forceFill([
            'payload' => $rawBody,
            'payload_hash' => hash('sha256', $rawBody),
            'payload_bytes' => strlen($rawBody),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function fillableAttributes(): array
    {
        return ['event_id', 'event_type', 'occurred_at', 'payload_version', 'max_attempts', 'trigger', 'metadata'];
    }

    /**
     * @return list<string>
     */
    protected function privateAttributes(): array
    {
        return ['endpoint_id', 'payload', 'payload_hash', 'next_attempt_triggered_by', 'subject_public_id'];
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function databaseDefaults(): array
    {
        return [
            'status' => 'pending',
            'attempts_count' => 0,
            'redelivery_requests' => 0,
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
            'claimed_until' => 'immutable_datetime',
            'exhausted_reason' => WebhookExhaustedReasonEnum::class,
            'next_attempt_trigger' => WebhookAttemptTriggerEnum::class,
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
