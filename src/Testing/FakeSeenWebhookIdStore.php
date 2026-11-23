<?php

declare(strict_types=1);

namespace Qiick\Outbound\Testing;

use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\WebhookIdStoreFailedException;
use RuntimeException;

/**
 * In-memory {@see SeenWebhookIdStore} for tests (atomic claim semantics).
 *
 * @api
 */
final class FakeSeenWebhookIdStore implements SeenWebhookIdStore
{
    /** @var array<string, int> hashed key => expiresAt unix */
    private array $entries = [];

    private bool $failNextWrite = false;

    /**
     * @param  (callable(): int)|null  $clock  Unix-seconds clock; defaults to time()
     */
    public function __construct(
        private readonly mixed $clock = null,
    ) {}

    public function failNextWrite(): self
    {
        $this->failNextWrite = true;

        return $this;
    }

    public function claim(string $scope, string $webhookId, int $ttlSeconds): bool
    {
        if ($this->failNextWrite) {
            $this->failNextWrite = false;

            throw new WebhookIdStoreFailedException('Webhook id dedupe store failed.', 0, new RuntimeException('forced cache write failure'));
        }

        $key = hash('sha256', $scope."\0".$webhookId);
        $now = $this->now();

        if (array_key_exists($key, $this->entries) && $this->entries[$key] > $now) {
            return false;
        }

        $this->entries[$key] = $now + max(1, $ttlSeconds);

        return true;
    }

    public function release(string $scope, string $webhookId): void
    {
        unset($this->entries[hash('sha256', $scope."\0".$webhookId)]);
    }

    private function now(): int
    {
        if (is_callable($this->clock)) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
