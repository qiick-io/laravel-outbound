<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * Optional webhook-id dedupe for the replay tolerance window.
 *
 * Timestamp tolerance alone does not prevent replays of the same id within
 * the window — consumers must claim ids (this store, or their own). Keys are
 * scoped (endpoint / tenant / secret id) so claims do not collide globally.
 *
 * @api
 */
interface SeenWebhookIdStore
{
    /**
     * Atomically claim `(scope, webhookId)` for `$ttlSeconds`.
     *
     * @return bool true if this caller won the claim; false if already claimed
     *
     * @throws WebhookException when the store cannot record the claim (fail closed)
     */
    public function claim(string $scope, string $webhookId, int $ttlSeconds): bool;
}
