<?php

declare(strict_types=1);

namespace Qiick\Outbound\Contracts;

use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Who hears that the package disabled an endpoint (410 Gone, the failure
 * streak). The package binds the endpoint's `created_by` user; a product
 * binds its own to reach a team or an operations address instead, without
 * turning `outbound.webhooks.auto_disable.notify_owner` off.
 *
 * @api
 */
interface WebhookEndpointNoticeRecipientsContract
{
    /**
     * Notifiables: models using Notifiable, or Notification::route(...).
     *
     * @return iterable<object>
     */
    public function recipientsFor(WebhookEndpoint $endpoint): iterable;
}
