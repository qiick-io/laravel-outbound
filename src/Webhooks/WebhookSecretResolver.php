<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Returns the secrets an endpoint signs with right now.
 *
 * The signing set is the primary secret plus the non-revoked secrets whose
 * `expires_at` is still in the future, primary first (standards review M3).
 * The package binds {@see DatabaseWebhookSecretResolver}; a product that keeps
 * its secrets elsewhere (a vault) binds its own implementation.
 *
 * @api
 */
interface WebhookSecretResolver
{
    /**
     * @throws WebhookException when the endpoint cannot sign: no primary, more
     *                          than one, or a stored secret that does not parse
     */
    public function signingSetFor(WebhookEndpoint $endpoint): WebhookSigningSet;
}
