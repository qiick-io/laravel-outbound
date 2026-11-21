<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Throwable;

/**
 * Cache-backed {@see SeenWebhookIdStore} using atomic {@see CacheRepository::add()}.
 *
 * Keys are `prefix` + sha256(scope + NUL + id) — bounded length, Memcached-safe.
 * The underlying store MUST implement atomic `add` (Redis, Memcached, database,
 * …). Drivers that fall back to get-then-put are refused. {@see ArrayStore} is
 * allowed only when constructed with `$allowArrayStore = true` (tests).
 *
 * @api
 */
final class CacheSeenWebhookIdStore implements SeenWebhookIdStore
{
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
            throw new WebhookException('Webhook id dedupe store failed.', 0, $e); // @pest-mutate-ignore: DecrementInteger,IncrementInteger
        }
    }

    /**
     * @throws \InvalidArgumentException when the store cannot provide atomic add
     */
    private function assertAtomicAdd(Store $store, bool $allowArrayStore): void
    {
        if ($store instanceof ArrayStore) {
            if (! $allowArrayStore) {
                throw new \InvalidArgumentException(
                    'CacheSeenWebhookIdStore refuses ArrayStore outside tests; use Redis, Memcached, or database cache.',
                );
            }

            return;
        }

        // Repository::add falls back to non-atomic get-then-put when the store
        // has no add() — refuse those drivers so claims stay race-safe.
        if (! method_exists($store, 'add')) {
            // Message shape is diagnostic-only; concat mutants are equivalent under class-name asserts.
            throw new \InvalidArgumentException(
                'CacheSeenWebhookIdStore requires a cache store with atomic add(); got '.$store::class.'.', // @pest-mutate-ignore: ConcatRemoveLeft,ConcatRemoveRight,ConcatSwitchSides
            );
        }
    }
}
