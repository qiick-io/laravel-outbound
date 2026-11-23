<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

use Carbon\CarbonImmutable;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;

/**
 * An endpoint stopped receiving deliveries: 410 Gone, the failure streak, or
 * a user. Fired once per disable, after the change is stored.
 *
 * Standard Webhooks "Deliverability and reliability": when delivery keeps
 * failing, notify the consumer through another channel (e.g. email) and
 * disable future delivery. The event carries the public id and the reason
 * only (standards review S5); a listener loads the endpoint if it needs more.
 *
 * @api
 */
final readonly class WebhookEndpointDisabledEvent
{
    public function __construct(
        public string $endpointPublicId,
        public WebhookEndpointDisabledReasonEnum $reason,
        public CarbonImmutable $disabledAt,
    ) {}
}
