<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Cache-backed {@see SeenWebhookIdStore} using atomic {@see CacheRepository::add()}.
 *
 * Keys are `prefix` + sha256(scope + NUL + id) — bounded length, Memcached-safe.
 *
 * @api
 */
final class CacheSeenWebhookIdStore implements SeenWebhookIdStore
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $keyPrefix = 'outbound:whid:',
    ) {}

    public function claim(string $scope, string $webhookId, int $ttlSeconds): bool
    {
        $key = $this->keyPrefix.hash('sha256', $scope."\0".$webhookId);
        $ttl = max(1, $ttlSeconds);

        try {
            return $this->cache->add($key, true, $ttl);
        } catch (WebhookException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WebhookException('Webhook id dedupe store failed.', 0, $e);
        }
    }
}
