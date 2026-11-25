<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Carbon\CarbonImmutable;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;

/**
 * Default {@see WebhookSecretResolver} over `webhook_endpoint_secrets`.
 *
 * Which rows sign is {@see WebhookEndpointSecret::scopeSigningAt()}. The set
 * fails closed: an endpoint without exactly one active primary does not sign,
 * because two primaries mean a rotation went wrong outside
 * {@see SecretRotator}, and signing with an old key alone would hide that.
 * The `max_secrets` cap is the signer's: a set above it is refused there.
 *
 * @api
 */
final class DatabaseWebhookSecretResolver implements WebhookSecretResolver
{
    /**
     * @param  bool  $allowRawLegacySecret  `outbound.webhooks.allow_raw_legacy_secret`:
     *                                      rows flagged `is_legacy_raw` may hold a
     *                                      non-`whsec_` secret during a migration
     */
    public function __construct(
        private readonly bool $allowRawLegacySecret = false,
    ) {}

    public function signingSetFor(WebhookEndpoint $endpoint): WebhookSigningSet
    {
        $signingSecrets = $endpoint->secrets()
            ->signingAt(CarbonImmutable::now())
            ->orderByDesc('is_primary')
            ->orderByDesc('expires_at')
            ->orderByDesc('id')
            ->get();

        $primaryCount = $signingSecrets->where('is_primary', true)->count();

        if ($primaryCount === 0) {
            throw new WebhookException('Webhook endpoint has no active primary signing secret.');
        }

        if ($primaryCount > 1) {
            throw new WebhookException('Webhook endpoint has more than one active primary signing secret.');
        }

        $secretsByPublicId = [];

        foreach ($signingSecrets as $signingSecret) {
            $secretsByPublicId[$signingSecret->public_id] = $this->parse($signingSecret);
        }

        return new WebhookSigningSet($secretsByPublicId);
    }

    /**
     * @throws WebhookException when the row has no bytes or they do not parse
     */
    private function parse(WebhookEndpointSecret $signingSecret): WebhookSecret
    {
        $serialized = $signingSecret->secret;

        if ($serialized === null) {
            throw new WebhookException('Webhook signing secret has no secret bytes.');
        }

        if ($signingSecret->is_legacy_raw) {
            return WebhookSecret::parseAllowingLegacyRaw($serialized, $this->allowRawLegacySecret);
        }

        return WebhookSecret::parse($serialized);
    }
}
