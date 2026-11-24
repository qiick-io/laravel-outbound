<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\StorageStore;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\UnsupportedWebhookIdCacheStoreException;
use Qiick\Outbound\Webhooks\WebhookIdClaim;
use Qiick\Outbound\Webhooks\WebhookIdStoreFailedException;

it('claims atomically with the array store when allowed for tests', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = new CacheSeenWebhookIdStore($cache, allowArrayStore: true);

    expect($store->claim(WebhookIdClaim::generate('scope-a', 'msg_1'), 60))->toBeTrue()
        ->and($store->claim(WebhookIdClaim::generate('scope-a', 'msg_1'), 60))->toBeFalse()
        ->and($store->claim(WebhookIdClaim::generate('scope-b', 'msg_1'), 60))->toBeTrue();
});

it('floors claim TTL at one second', function (): void {
    $inner = new class extends ArrayStore
    {
        public ?int $seenTtl = null;

        public function add($key, $value, $seconds): bool
        {
            $this->seenTtl = $seconds;

            return true;
        }
    };
    $store = new CacheSeenWebhookIdStore(new Repository($inner), allowArrayStore: true);

    expect($store->claim(WebhookIdClaim::generate('s', 'id'), 0))->toBeTrue()
        ->and($inner->seenTtl)->toBe(1)
        ->and($store->claim(WebhookIdClaim::generate('s', 'id2'), -5))->toBeTrue()
        ->and($inner->seenTtl)->toBe(1);
});

it('hashes scope and id into a bounded Memcached-safe key', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = new CacheSeenWebhookIdStore($cache, keyPrefix: 't:', allowArrayStore: true);

    expect($store->claim(WebhookIdClaim::generate('tenant', 'msg_'.str_repeat('x', 200)), 30))->toBeTrue();

    $keys = array_keys($cache->getStore()->all());
    expect($keys)->toHaveCount(1)
        ->and($keys[0])->toStartWith('t:')
        ->and(strlen($keys[0]))->toBe(2 + 64) // prefix + sha256 hex
        ->and($keys[0])->toBe('t:'.hash('sha256', "tenant\0".'msg_'.str_repeat('x', 200)));
});

it('refuses ArrayStore outside tests with a dedicated exception', function (): void {
    expect(fn () => new CacheSeenWebhookIdStore(new Repository(new ArrayStore)))
        ->toThrow(UnsupportedWebhookIdCacheStoreException::class, 'refuses ArrayStore outside tests');
});

it('accepts the database store', function (): void {
    $inner = new class extends DatabaseStore
    {
        public function __construct() {}
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->not->toThrow(UnsupportedWebhookIdCacheStoreException::class);
});

it('accepts the redis store', function (): void {
    $inner = new class extends RedisStore
    {
        public function __construct() {}
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->not->toThrow(UnsupportedWebhookIdCacheStoreException::class);
});

it('accepts the memcached store', function (): void {
    $inner = new class extends MemcachedStore
    {
        public function __construct() {}
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->not->toThrow(UnsupportedWebhookIdCacheStoreException::class);
});

it('accepts the dynamodb store', function (): void {
    $inner = new class extends DynamoDbStore
    {
        public function __construct() {}
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->not->toThrow(UnsupportedWebhookIdCacheStoreException::class);
});

it('accepts the file store (single-host)', function (): void {
    $inner = new class extends FileStore
    {
        public function __construct() {}
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->not->toThrow(UnsupportedWebhookIdCacheStoreException::class);
});

it('refuses the storage store even though it exposes add', function (): void {
    $inner = new class extends StorageStore
    {
        public function __construct() {}
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->toThrow(UnsupportedWebhookIdCacheStoreException::class, StorageStore::class);
})->skip(
    ! class_exists(StorageStore::class),
    'StorageStore is not available on this Illuminate version',
);

it('refuses the failover store even though it exposes add', function (): void {
    $inner = new class extends FailoverStore
    {
        public function __construct() {}
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->toThrow(UnsupportedWebhookIdCacheStoreException::class, FailoverStore::class);
});

it('refuses a non-allowlisted store that still implements add', function (): void {
    // NullStore is outside the allowlist; adding add() must not make it acceptable
    // (mirrors StorageStore's get-then-put add that method_exists alone would pass).
    $inner = new class extends NullStore
    {
        public function add($key, $value, $seconds): bool
        {
            if ($this->get($key) !== null) {
                return false;
            }

            return $this->put($key, $value, $seconds);
        }
    };

    expect(fn () => new CacheSeenWebhookIdStore(new Repository($inner)))
        ->toThrow(UnsupportedWebhookIdCacheStoreException::class, $inner::class);
});

it('refuses stores outside the allowlist and names the store class', function (): void {
    expect(fn () => new CacheSeenWebhookIdStore(new Repository(new NullStore)))
        ->toThrow(UnsupportedWebhookIdCacheStoreException::class, NullStore::class);
});

it('wraps cache failures as WebhookIdStoreFailedException', function (): void {
    $inner = new class extends ArrayStore
    {
        public function add($key, $value, $seconds): bool
        {
            throw new RuntimeException('cache down');
        }
    };
    $store = new CacheSeenWebhookIdStore(new Repository($inner), allowArrayStore: true);

    expect(fn () => $store->claim(WebhookIdClaim::generate('s', 'id'), 10))
        ->toThrow(WebhookIdStoreFailedException::class, 'Webhook id dedupe store failed.');
});
