<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

/**
 * Outcome of one attempt (`webhook_delivery_attempts.status`): delivered, a
 * failure the schedule may retry, or refused before any byte left (the guard
 * or the signer stopped it). Narrower than the delivery row's
 * `DeliveryStatus` on purpose: an attempt is never pending, exhausted or
 * cancelled (standards review M7). Backing values are stored, so they never
 * change, and match the same `DeliveryStatus` cases.
 *
 * @api
 */
enum WebhookAttemptStatusEnum: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Refused = 'refused';
}
