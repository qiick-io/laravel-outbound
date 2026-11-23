<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;

/**
 * A user asked for deliveries to be sent again: one (`manual`) or a range
 * (`bulk`). Fired once per request, after the deliveries are scheduled, with
 * public ids and counts only (standards review S5), for a product's audit log.
 *
 * @api
 */
final readonly class WebhookRedeliveryRequestedEvent
{
    public function __construct(
        public string $endpointPublicId,
        public int $deliveryCount,
        public WebhookAttemptTriggerEnum $trigger,
        public ?int $triggeredBy,
    ) {}
}
