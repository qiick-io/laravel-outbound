<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

/**
 * What started one attempt (`webhook_delivery_attempts.trigger`): the retry
 * schedule, a single manual redelivery, a bulk redelivery over a time range,
 * or a test send. Backing values are stored, so they never change.
 *
 * @api
 */
enum WebhookAttemptTriggerEnum: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Bulk = 'bulk';
    case Test = 'test';
}
