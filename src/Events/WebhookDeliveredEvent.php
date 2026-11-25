<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A receiver accepted a webhook (a 2xx), and the attempt is stored. The
 * counterpart of {@see WebhookDeliveryFailedEvent}, so a product records the
 * outcome without querying the delivery tables. Public ids, the webhook id
 * and the attempt number only; a listener loads the delivery if it needs
 * more. Dispatched after the commit, so a rolled-back run announces nothing.
 *
 * @api
 */
final readonly class WebhookDeliveredEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $deliveryPublicId,
        public string $eventId,
        public string $endpointPublicId,
        public int $attempt,
        public CarbonImmutable $deliveredAt,
    ) {}
}
