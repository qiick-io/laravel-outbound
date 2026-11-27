<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

use Carbon\CarbonImmutable;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;

/**
 * An attempt left a delivery exhausted: every attempt used, a Retry-After
 * above the cap, or 410 Gone. Fired once, after the attempt is stored.
 *
 * Standard Webhooks "Deliverability and reliability": the product can tell
 * the endpoint's owner through another channel. The event carries public ids
 * and the reason only (standards review S5); a listener loads the delivery if
 * it needs more. A body pruned while its delivery was still pending exhausts
 * it without an attempt, and without this event.
 *
 * @api
 */
final readonly class WebhookDeliveryFailedEvent
{
    public function __construct(
        public string $deliveryPublicId,
        public string $endpointPublicId,
        public string $eventType,
        public WebhookExhaustedReasonEnum $reason,
        public int $attempts,
        public CarbonImmutable $failedAt,
    ) {}
}
