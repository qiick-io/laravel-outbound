<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * Optional webhook-id dedupe for the replay tolerance window.
 *
 * Timestamp tolerance alone does not prevent replays of the same id within
 * the window — consumers must dedupe on webhook-id (this store, or their own).
 *
 * @api
 */
interface SeenWebhookIdStore
{
    public function has(string $webhookId): bool;

    /**
     * Remember $webhookId until roughly $ttlSeconds elapses.
     */
    public function remember(string $webhookId, int $ttlSeconds): void;
}
