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
     * Atomically claim `(scope, webhookId)` for `$ttlSeconds`, holding the
     * claim's token so that only this claim can release it.
     *
     * @return bool true if this caller won the claim; false if already claimed
     *
     * @throws WebhookException when the store cannot record the claim (fail closed)
     */
    public function claim(WebhookIdClaim $claim, int $ttlSeconds): bool;

    /**
     * Give a claim back, so the same `(scope, webhookId)` can be processed
     * again: for a consumer whose processing failed after the claim, which
     * the sender's redelivery will retry with the same `webhook-id`
     * (standards review M14, R7). Only the claim that won does anything:
     * one that lost, or an id never claimed, leaves the store as it is.
     *
     * @throws WebhookException when the store cannot release the claim (fail closed)
     */
    public function release(WebhookIdClaim $claim): void;
}
