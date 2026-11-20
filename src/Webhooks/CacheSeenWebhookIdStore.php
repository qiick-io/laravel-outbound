<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache-backed {@see SeenWebhookIdStore} (default when the service provider binds the verifier).
 *
 * @api
 */
final class CacheSeenWebhookIdStore implements SeenWebhookIdStore
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $keyPrefix = 'outbound:webhook-id:',
    ) {}

    public function has(string $webhookId): bool
    {
        return $this->cache->has($this->keyPrefix.$webhookId);
    }

    public function remember(string $webhookId, int $ttlSeconds): void
    {
        $ttl = max(1, $ttlSeconds);
        $this->cache->put($this->keyPrefix.$webhookId, true, $ttl);
    }
}
