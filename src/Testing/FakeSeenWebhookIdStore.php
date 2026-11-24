<?php

declare(strict_types=1);

namespace Qiick\Outbound\Testing;

use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\WebhookIdClaim;
use Qiick\Outbound\Webhooks\WebhookIdStoreFailedException;
use RuntimeException;

/**
 * In-memory {@see SeenWebhookIdStore} for tests (atomic claim semantics).
 *
 * @api
 */
final class FakeSeenWebhookIdStore implements SeenWebhookIdStore
{
    /** @var array<string, array{expiresAt: int, token: string}> hashed key => claim */
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

    public function claim(WebhookIdClaim $claim, int $ttlSeconds): bool
    {
        if ($this->failNextWrite) {
            $this->failNextWrite = false;

            throw new WebhookIdStoreFailedException('Webhook id dedupe store failed.', 0, new RuntimeException('forced cache write failure'));
        }

        $key = self::keyFor($claim);
        $now = $this->now();

        if (array_key_exists($key, $this->entries) && $this->entries[$key]['expiresAt'] > $now) {
            return false;
        }

        $this->entries[$key] = ['expiresAt' => $now + max(1, $ttlSeconds), 'token' => $claim->token];

        return true;
    }

    public function release(WebhookIdClaim $claim): void
    {
        $key = self::keyFor($claim);

        if (($this->entries[$key]['token'] ?? null) === $claim->token) {
            unset($this->entries[$key]);
        }
    }

    private static function keyFor(WebhookIdClaim $claim): string
    {
        return hash('sha256', $claim->scope."\0".$claim->webhookId);
    }

    private function now(): int
    {
        if (is_callable($this->clock)) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
