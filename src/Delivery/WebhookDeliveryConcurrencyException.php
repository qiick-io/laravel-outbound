<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Illuminate\Database\DeadlockException;

/**
 * A webhook delivery write lost a deadlock or a serialization conflict.
 *
 * A `DeadlockException` with SQLSTATE 40001, so `DB::transaction(..., attempts: n)`
 * still retries the product's transaction, but with a fixed message and no
 * previous exception: Laravel's own carries the SQL and every binding,
 * `payload_hash` included.
 *
 * @api
 */
final class WebhookDeliveryConcurrencyException extends DeadlockException {}
