<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use DateTimeImmutable;

/**
 * Immutable record of one outbound delivery attempt (product-owned storage).
 *
 * Control characters are stripped and length is capped on `$errorMessage`, but
 * callers must still remove URLs, tokens, signing secrets, and other
 * credentials before construction.
 *
 * @api
 */
final readonly class DeliveryAttempt
{
    public const int MAX_ERROR_MESSAGE_BYTES = 500;

    public readonly ?string $errorMessage;

    /**
     * @param  non-empty-string  $eventId
     * @param  non-empty-string  $eventType
     * @param  positive-int  $attempt  1-based attempt number
     * @param  int<100, 599>|null  $httpStatus
     *
     * @throws \InvalidArgumentException on empty ids, non-positive attempt,
     *                                   out-of-range http status, or nextAttemptAt before at
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public int $attempt,
        public DeliveryStatus $status,
        public ?int $httpStatus,
        ?string $errorMessage,
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

        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new \InvalidArgumentException('Delivery attempt http status must be between 100 and 599.');
        }

        if ($nextAttemptAt !== null && $nextAttemptAt < $at) {
            throw new \InvalidArgumentException('Delivery attempt nextAttemptAt must not be before at.');
        }

        $this->errorMessage = self::sanitiseErrorMessage($errorMessage);
    }

    private static function sanitiseErrorMessage(?string $errorMessage): ?string
    {
        if ($errorMessage === null) {
            return null;
        }

        $stripped = preg_replace('/[\x00-\x1F\x7F]/u', '', $errorMessage) ?? '';

        if (strlen($stripped) > self::MAX_ERROR_MESSAGE_BYTES) {
            return substr($stripped, 0, self::MAX_ERROR_MESSAGE_BYTES);
        }

        return $stripped;
    }
}
