<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * {@see CacheSeenWebhookIdStore} refused the configured cache driver.
 *
 * Thrown when the store is constructed (and therefore when the container resolves
 * {@see SeenWebhookIdStore} or {@see StandardWebhookVerifier}). Redis and
 * database locks support atomic owner-checked release. Array is tests-only.
 *
 * @api
 */
final class UnsupportedWebhookIdCacheStoreException extends WebhookException {}
