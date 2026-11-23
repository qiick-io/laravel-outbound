<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

use Carbon\CarbonImmutable;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;

/**
 * A user asked for deliveries to be sent again: one (`manual`) or a range
 * (`bulk`). Fired once per request, after the deliveries are scheduled, with
 * public values only (standards review S5), enough for a product's audit log
 * to rebuild the request: the delivery for `manual`, the range and statuses
 * for `bulk`.
 *
 * @api
 */
final readonly class WebhookRedeliveryRequestedEvent
{
    /**
     * @param  string|null  $deliveryPublicId  the delivery, for `manual`
     * @param  CarbonImmutable|null  $from  the range's start, for `bulk`
     * @param  CarbonImmutable|null  $until  the range's end (excluded), for `bulk`
     * @param  list<DeliveryStatus>  $statuses  the statuses asked for, for `bulk`
     */
    public function __construct(
        public string $endpointPublicId,
        public int $deliveryCount,
        public WebhookAttemptTriggerEnum $trigger,
        public ?int $triggeredBy,
        public ?string $deliveryPublicId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $until = null,
        public array $statuses = [],
    ) {}
}
