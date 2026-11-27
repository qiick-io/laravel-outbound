<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;

/**
 * Issues, rotates and revokes an endpoint's `v1` signing secrets.
 *
 * Standard Webhooks "Webhook headers": during a rotation the sender signs with
 * the new key and an old one "for a set period of time", so a consumer can
 * switch over without dropping messages. Here the old primary keeps signing
 * until its `expires_at` (the grace period), and the signing set never grows
 * past `max_secrets` (standards review M3).
 *
 * Every change runs in a transaction that locks the endpoint row first, so two
 * concurrent rotations cannot both demote the same primary and leave two.
 *
 * The returned secret's `secret` attribute holds the plaintext `whsec_`
 * string: the product shows it once, right after issue or rotate, and never
 * again (it is hidden from arrays and JSON).
 *
 * @api
 */
final class SecretRotator
{
    /**
     * @param  int  $maxSecrets  `outbound.webhooks.max_secrets`, the signer's cap
     * @param  int  $defaultGraceSeconds  `outbound.webhooks.secret_rotation_grace_seconds`
     */
    /**
     * @throws WebhookException when `$maxSecrets` is outside 1–4: four is the
     *                          fleet's hard limit (standards review M3)
     *
     * @internal Resolve the production service from the container.
     */
    public function __construct(
        private readonly int $maxSecrets = 4,
        private readonly int $defaultGraceSeconds = 86_400,
    ) {
        if ($maxSecrets < 1 || $maxSecrets > 4) {
            throw new WebhookException('outbound.webhooks.max_secrets must be between 1 and 4.');
        }
    }

    /**
     * Give an endpoint its first primary secret.
     *
     * @throws WebhookException when the endpoint is deleted, already has an
     *                          active primary, or would exceed `max_secrets`
     */
    public function issue(WebhookEndpoint $endpoint): WebhookEndpointSecret
    {
        return $this->whileEndpointLocked($endpoint->id, function (WebhookEndpoint $lockedEndpoint): WebhookEndpointSecret {
            $signingSecrets = $this->signingSecretsOfLive($lockedEndpoint);

            if ($signingSecrets->contains('is_primary', true)) {
                throw new WebhookException('Webhook endpoint already has a primary secret; rotate it instead.');
            }

            if ($signingSecrets->count() + 1 > $this->maxSecrets) {
                throw new WebhookException("Issuing would exceed {$this->maxSecrets} signing secrets; revoke an old secret first.");
            }

            return $this->createPrimary($lockedEndpoint);
        });
    }

    /**
     * Replace the primary secret. The old primary keeps signing for
     * `$graceSeconds` (the configured default when null); zero revokes it at
     * once, which is the answer to a leaked secret.
     *
     * @throws WebhookException when the grace period is negative, the endpoint
     *                          is deleted or has no primary, or the set would
     *                          exceed `max_secrets`
     */
    public function rotate(WebhookEndpoint $endpoint, ?int $graceSeconds = null): WebhookEndpointSecret
    {
        $graceSeconds ??= $this->defaultGraceSeconds;

        if ($graceSeconds < 0) {
            throw new WebhookException('Webhook secret grace period must not be negative.');
        }

        return $this->whileEndpointLocked($endpoint->id, function (WebhookEndpoint $lockedEndpoint) use ($graceSeconds): WebhookEndpointSecret {
            $now = CarbonImmutable::now();
            $signingSecrets = $this->signingSecretsOfLive($lockedEndpoint);
            $primary = $signingSecrets->firstWhere('is_primary', true);

            if ($primary === null) {
                throw new WebhookException('Webhook endpoint has no primary secret; issue one first.');
            }

            $keepsOldPrimary = $graceSeconds > 0;
            $signingCountAfterRotation = $signingSecrets->count() + ($keepsOldPrimary ? 1 : 0);

            if ($signingCountAfterRotation > $this->maxSecrets) {
                throw new WebhookException("Rotating would exceed {$this->maxSecrets} signing secrets; revoke an old secret first.");
            }

            $primary->forceFill($keepsOldPrimary
                ? ['is_primary' => false, 'expires_at' => $now->addSeconds($graceSeconds)]
                : ['is_primary' => false, 'revoked_at' => $now])->save();

            return $this->createPrimary($lockedEndpoint);
        });
    }

    /**
     * Stop an old secret signing at once. Revoking twice keeps the first time.
     * Works on a soft-deleted endpoint too, so its secrets can be retired
     * after the endpoint itself.
     *
     * @throws WebhookException when the secret is the active primary: an
     *                          endpoint must always have one, so rotate instead
     */
    public function revoke(WebhookEndpointSecret $secret): void
    {
        $this->whileEndpointLocked($secret->endpoint_id, function () use ($secret): void {
            // The caller's model may predate a rotation; decide on the stored row.
            $secret->refresh();

            if ($secret->revoked_at !== null) {
                return;
            }

            if ($secret->is_primary) {
                throw new WebhookException('The primary webhook secret cannot be revoked; rotate it with a zero grace period instead.');
            }

            $secret->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        });
    }

    /**
     * @template TResult
     *
     * @param  Closure(WebhookEndpoint): TResult  $change
     * @return TResult
     */
    private function whileEndpointLocked(int $endpointId, Closure $change): mixed
    {
        return WebhookEndpoint::query()->getConnection()->transaction(function () use ($endpointId, $change): mixed {
            $lockedEndpoint = WebhookEndpoint::withTrashed()->whereKey($endpointId)->lockForUpdate()->firstOrFail();

            return $change($lockedEndpoint);
        });
    }

    /**
     * A deleted endpoint gets no new secret: it must not start signing again.
     *
     * @return Collection<int, WebhookEndpointSecret>
     *
     * @throws WebhookException when the endpoint is soft-deleted
     */
    private function signingSecretsOfLive(WebhookEndpoint $endpoint): Collection
    {
        if ($endpoint->trashed()) {
            throw new WebhookException('Webhook endpoint is deleted.');
        }

        return $endpoint->secrets()->signingAt(CarbonImmutable::now())->get();
    }

    /**
     * The table default makes the new secret `v1`.
     */
    private function createPrimary(WebhookEndpoint $endpoint): WebhookEndpointSecret
    {
        $primary = $endpoint->secrets()->make()
            ->useSecret(WebhookSecret::generate())
            ->forceFill(['is_primary' => true]);
        $primary->save();

        return $primary;
    }
}
