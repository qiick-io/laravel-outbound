<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * A webhook delivery row could not be written.
 *
 * Laravel's `QueryException` prints every binding of the failed statement,
 * `payload_hash` included, and error handlers log it with its whole chain.
 * This exception keeps only the SQLSTATE and has no previous exception, so
 * the hash never reaches a message or a log.
 *
 * @api
 */
class WebhookDeliveryStorageException extends RuntimeException
{
    public static function withoutBindings(QueryException $queryException): self
    {
        $message = sprintf('Webhook delivery could not be saved (SQLSTATE %s).', $queryException->getCode());

        if ($queryException instanceof UniqueConstraintViolationException) {
            return new DuplicateWebhookDeliveryException($message);
        }

        return new self($message);
    }
}
