<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * The secrets that sign one webhook attempt, keyed by their public ids.
 *
 * The public ids travel with the secrets so the attempt can record which
 * secrets signed it (`webhook_delivery_attempts.secret_public_ids`) without
 * ever storing secret bytes. The order is the header order: primary first.
 *
 * @api
 */
final readonly class WebhookSigningSet
{
    /**
     * @param  array<string, WebhookSecret>  $secretsByPublicId  Primary secret first.
     *
     * @throws WebhookException when the set is empty or holds a non-secret value
     */
    public function __construct(
        private array $secretsByPublicId,
    ) {
        if ($secretsByPublicId === []) {
            throw new WebhookException('A webhook signing set needs at least one secret.');
        }

        foreach ($secretsByPublicId as $secret) {
            if (! $secret instanceof WebhookSecret) {
                throw new WebhookException('Webhook secrets must be WebhookSecret instances.');
            }
        }
    }

    /**
     * The secrets to hand to {@see StandardWebhookSigner::sign()}.
     *
     * @return list<WebhookSecret>
     */
    public function secrets(): array
    {
        return array_values($this->secretsByPublicId);
    }

    /**
     * PHP turns numeric-string array keys into ints; the ids are cast back.
     *
     * @return list<string>
     */
    public function secretPublicIds(): array
    {
        return array_map(strval(...), array_keys($this->secretsByPublicId));
    }
}
