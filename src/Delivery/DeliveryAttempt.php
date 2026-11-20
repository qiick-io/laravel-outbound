<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use DateTimeImmutable;

/**
 * Immutable record of one outbound delivery attempt (product-owned storage).
 *
 * @api
 */
final readonly class DeliveryAttempt
{
    /**
     * @param  non-empty-string  $eventId
     * @param  non-empty-string  $eventType
     * @param  positive-int  $attempt  1-based attempt number
     *
     * @throws \InvalidArgumentException on empty ids or non-positive attempt
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public int $attempt,
        public DeliveryStatus $status,
        public ?int $httpStatus,
        public ?string $errorMessage,
        public DateTimeImmutable $at,
        public ?DateTimeImmutable $nextAttemptAt,
    ) {
        if ($eventId === '') {
            throw new \InvalidArgumentException('Delivery attempt event id must not be empty.');
        }

        if ($eventType === '') {
            throw new \InvalidArgumentException('Delivery attempt event type must not be empty.');
        }

        if ($attempt < 1) {
            throw new \InvalidArgumentException('Delivery attempt number must be at least 1.');
        }
    }
}
