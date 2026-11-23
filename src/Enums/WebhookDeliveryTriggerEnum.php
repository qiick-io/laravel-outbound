<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

/**
 * What created a delivery row (`webhook_deliveries.trigger`): a domain event,
 * a delivery a user created by hand (`Manual`, or `Bulk` for many at once),
 * or "Send test" in the product UI.
 *
 * A redelivery is not a new row: it adds attempts to the existing row and
 * keeps its `webhook-id` (standards review R7), with the trigger recorded on
 * the attempt ({@see WebhookAttemptTriggerEnum}). Backing values are stored,
 * so they never change.
 *
 * @api
 */
enum WebhookDeliveryTriggerEnum: string
{
    case Event = 'event';
    case Manual = 'manual';
    case Bulk = 'bulk';
    case Test = 'test';
}
