<?php

declare(strict_types=1);

namespace Qiick\Outbound\Testing;

use Qiick\Outbound\Webhooks\SeenWebhookIdStore;

/**
 * In-memory {@see SeenWebhookIdStore} for tests.
 *
 * @api
 */
final class FakeSeenWebhookIdStore implements SeenWebhookIdStore
{
    /** @var array<string, int> webhookId => expiresAt unix */
    private array $entries = [];

    /**
     * @param  (callable(): int)|null  $clock  Unix-seconds clock; defaults to time()
     */
    public function __construct(
        private readonly mixed $clock = null,
    ) {}

    public function has(string $webhookId): bool
    {
        if (! array_key_exists($webhookId, $this->entries)) {
            return false;
        }

        if ($this->entries[$webhookId] <= $this->now()) {
            unset($this->entries[$webhookId]);

            return false;
        }

        return true;
    }

    public function remember(string $webhookId, int $ttlSeconds): void
    {
        $this->entries[$webhookId] = $this->now() + max(1, $ttlSeconds);
    }

    private function now(): int
    {
        if (is_callable($this->clock)) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
