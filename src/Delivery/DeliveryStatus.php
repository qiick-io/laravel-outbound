<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

/**
 * Status of a webhook delivery row and outcome of a single attempt.
 *
 * An attempt is `Delivered`, `Failed` or `Refused` (the guard or the signer
 * stopped it before any byte left). A delivery row is additionally `Pending`
 * while retries remain, `Exhausted` when automatic retries stopped, and
 * `Cancelled` when the product withdrew it. All cases exist before 1.0
 * because adding one later breaks exhaustive `match` in products (standards
 * review M7). Backing values are stored in `webhook_deliveries.status` and
 * `webhook_delivery_attempts.status`, so they never change.
 *
 * @api
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Refused = 'refused';
    case Exhausted = 'exhausted';
    case Cancelled = 'cancelled';
}
