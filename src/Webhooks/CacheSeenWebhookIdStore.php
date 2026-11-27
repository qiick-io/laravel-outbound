<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Throwable;

/**
 * Cache-backed {@see SeenWebhookIdStore} with owner-checked Laravel locks.
 *
 * Redis and database locks compare ownership and delete in the same storage
 * operation. Array locks are available only for process-local tests.
 *
 * @api
 */
final class CacheSeenWebhookIdStore implements SeenWebhookIdStore
{
    private readonly LockProvider $locks;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $keyPrefix = 'outbound:whid:',
        bool $allowArrayStore = false,
    ) {
        $this->locks = self::atomicLocks($this->cache->getStore(), $allowArrayStore);
    }

    public function claim(WebhookIdClaim $claim, int $ttlSeconds): bool
    {
        try {
            // A still-live beta record can expire on its original schedule.
            if ($this->cache->has($this->keyFor($claim))) {
                return false;
            }

            $lock = $this->locks->lock($this->keyFor($claim, 'lock:'), max(1, $ttlSeconds), $claim->token);

            return $lock instanceof DatabaseLock ? DatabaseWebhookIdLock::acquireClaim($lock) : $lock->get() === true;
        } catch (WebhookException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WebhookIdStoreFailedException('Webhook id dedupe store failed.', previous: $exception);
        }
    }

    public function release(WebhookIdClaim $claim): void
    {
        try {
            $this->locks->restoreLock($this->keyFor($claim, 'lock:'), $claim->token)->release();
        } catch (Throwable $exception) {
            throw new WebhookIdStoreFailedException('Webhook id dedupe store failed.', previous: $exception);
        }
    }

    private function keyFor(WebhookIdClaim $claim, string $namespace = ''): string
    {
        return $this->keyPrefix.$namespace.hash('sha256', $claim->scope."\0".$claim->webhookId);
    }

    /**
     * @throws UnsupportedWebhookIdCacheStoreException when the driver's release
     *                                                 cannot atomically verify ownership
     */
    private static function atomicLocks(Store $store, bool $allowArrayStore): LockProvider
    {
        if ($store instanceof ArrayStore) {
            if (! $allowArrayStore) {
                throw new UnsupportedWebhookIdCacheStoreException(
                    'CacheSeenWebhookIdStore refuses ArrayStore outside tests; use database or redis cache.',
                );
            }

            return $store;
        }

        if ($store instanceof DatabaseStore || $store instanceof RedisStore) {
            return $store;
        }

        throw new UnsupportedWebhookIdCacheStoreException(
            'CacheSeenWebhookIdStore refuses cache store '.$store::class.'; use database or redis with atomic owner-checked release. Array is tests-only.',
        );
    }
}
