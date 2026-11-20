<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

/**
 * Outcome of a single outbound delivery attempt.
 *
 * @api
 */
enum DeliveryStatus: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Refused = 'refused';
}
