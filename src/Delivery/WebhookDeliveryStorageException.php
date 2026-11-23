<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * A webhook delivery row could not be written.
 *
 * Laravel's `QueryException` (and the `DeadlockException` it becomes inside
 * a transaction) prints every binding of the failed statement,
 * `payload_hash` included, and error handlers log it with its whole chain.
 * This exception keeps only the SQLSTATE and has no previous exception, so
 * the hash never reaches a message or a log.
 *
 * @api
 */
class WebhookDeliveryStorageException extends RuntimeException
{
    /**
     * The original is a sensitive parameter: error reporters that print
     * stack-frame arguments (Flare, Ignition) must not print it either.
     */
    public static function withoutBindings(
        #[\SensitiveParameter]
        PDOException $databaseException,
    ): self|WebhookDeliveryConcurrencyException {
        if (self::isConcurrencyError($databaseException)) {
            return new WebhookDeliveryConcurrencyException('Webhook delivery could not be saved: deadlock or serialization failure (SQLSTATE 40001).', 40001);
        }

        $message = sprintf('Webhook delivery could not be saved (SQLSTATE %s).', $databaseException->getCode());

        if ($databaseException instanceof UniqueConstraintViolationException) {
            return new DuplicateWebhookDeliveryException($message);
        }

        return new self($message);
    }

    /**
     * Laravel's own rule, so retries of the product's transaction agree with it.
     */
    private static function isConcurrencyError(
        #[\SensitiveParameter]
        Throwable $databaseException,
    ): bool {
        $detector = new class
        {
            use DetectsConcurrencyErrors;

            public function detects(Throwable $exception): bool
            {
                return $this->causedByConcurrencyError($exception);
            }
        };

        return $detector->detects($databaseException);
    }
}
