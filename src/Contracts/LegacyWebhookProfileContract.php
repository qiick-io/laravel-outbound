<?php

declare(strict_types=1);

namespace Qiick\Outbound\Contracts;

use Qiick\Outbound\Webhooks\SignedWebhook;
use Qiick\Outbound\Webhooks\WebhookMessage;
use Qiick\Outbound\Webhooks\WebhookSecret;

/**
 * A product's older webhook format, kept for an endpoint whose
 * `legacy_profile` names it while its consumers migrate (standards review
 * S8): externa 1.6 sends its `X-Externa-*` headers and a superset body next
 * to Standard Webhooks. Registered by name in
 * `outbound.webhooks.legacy_profiles`.
 *
 * The package calls it without knowing the format, so it has no condition
 * for any product.
 *
 * @api
 */
interface LegacyWebhookProfileContract
{
    /**
     * The body to store, sign and send: the Standard Webhooks body, or a
     * superset of its keys. Called once, at dispatch, so every attempt and
     * redelivery sends the same bytes.
     */
    public function body(WebhookMessage $message, string $standardBody): string;

    /**
     * Headers sent with each attempt next to the Standard Webhooks ones,
     * which they may not name. The signed webhook carries the id, the
     * timestamp and the exact body, and the secrets are the endpoint's
     * current signing secrets, for a legacy signature.
     *
     * @param  list<WebhookSecret>  $secrets
     * @return array<string, string>
     */
    public function headers(SignedWebhook $signedWebhook, array $secrets): array;
}
