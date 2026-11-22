<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Throwable;

/**
 * Cache-backed {@see SeenWebhookIdStore} using atomic {@see CacheRepository::add()}.
 *
 * Keys are `prefix` + sha256(scope + NUL + id) — bounded length, Memcached-safe.
 *
 * Only an explicit allowlist of stores with atomic `add` is accepted: database,
 * redis, memcached, dynamodb, and file (process-safe locks on a **single host**
 * only — not for multi-node fleets). {@see ArrayStore} is allowed only when
 * constructed with `$allowArrayStore = true` (tests). Drivers such as
 * `storage` (get-then-put) and `failover` are refused even when they expose
 * `add()`.
 *
 * @api
 */
final class CacheSeenWebhookIdStore implements SeenWebhookIdStore
{
    /**
     * Store classes whose `add()` is atomic enough for webhook-id claims.
     *
     * @var list<class-string<Store>>
     */
    private const ATOMIC_STORE_CLASSES = [
        DatabaseStore::class,
        RedisStore::class,
        MemcachedStore::class,
        DynamoDbStore::class,
        FileStore::class,
    ];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $keyPrefix = 'outbound:whid:',
        bool $allowArrayStore = false,
    ) {
        $this->assertAtomicAdd($this->cache->getStore(), $allowArrayStore);
    }

    public function claim(string $scope, string $webhookId, int $ttlSeconds): bool
    {
        $key = $this->keyPrefix.hash('sha256', $scope."\0".$webhookId);
        $ttl = max(1, $ttlSeconds);

        try {
            // Stored value is opaque; only add()'s boolean matters for claim semantics.
            return $this->cache->add($key, true, $ttl); // @pest-mutate-ignore: TrueToFalse
        } catch (WebhookException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WebhookIdStoreFailedException('Webhook id dedupe store failed.', 0, $e); // @pest-mutate-ignore: DecrementInteger,IncrementInteger
        }
    }

    /**
     * @throws UnsupportedWebhookIdCacheStoreException when the store is not allowlisted
     */
    private function assertAtomicAdd(Store $store, bool $allowArrayStore): void
    {
        if ($store instanceof ArrayStore) {
            if (! $allowArrayStore) {
                throw new UnsupportedWebhookIdCacheStoreException(
                    'CacheSeenWebhookIdStore refuses ArrayStore outside tests; use database, redis, memcached, dynamodb, or file (single-host) cache.',
                );
            }

            return;
        }

        foreach (self::ATOMIC_STORE_CLASSES as $allowedClass) {
            if ($store instanceof $allowedClass) {
                return;
            }
        }

        throw new UnsupportedWebhookIdCacheStoreException(
            'CacheSeenWebhookIdStore refuses cache store '.$store::class.'; use database, redis, memcached, dynamodb, or file (single-host). Array is tests-only.', // @pest-mutate-ignore: ConcatRemoveLeft,ConcatRemoveRight,ConcatSwitchSides
        );
    }
}
