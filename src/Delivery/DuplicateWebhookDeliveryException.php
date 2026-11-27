<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

/**
 * The endpoint already has a delivery for this event (`endpoint_id`,
 * `event_id` is unique), or another unique key refused the row.
 *
 * @api
 */
final class DuplicateWebhookDeliveryException extends WebhookDeliveryStorageException {}
