<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * {@see CacheSeenWebhookIdStore} refused the configured cache driver.
 *
 * Thrown when the store is constructed (and therefore when the container resolves
 * {@see SeenWebhookIdStore} / {@see StandardWebhookVerifier}), not as a generic
 * request 500 later. Allowed drivers: database, redis, memcached, dynamodb, file
 * (single-host only), and array in the testing environment only.
 *
 * @api
 */
final class UnsupportedWebhookIdCacheStoreException extends WebhookException {}
