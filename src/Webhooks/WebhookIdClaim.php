<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * Proof that one request won the claim on a `(scope, webhook-id)`.
 *
 * {@see StandardWebhookVerifier::verify()} returns it, and only it can
 * {@see SeenWebhookIdStore::release()} that claim: a duplicate gets none, so
 * a generic `catch` has nothing to give back, and the random token stops a
 * request from releasing a claim another one holds (standards review M14).
 *
 * @api
 */
final readonly class WebhookIdClaim
{
    public function __construct(
        public string $scope,
        public string $webhookId,
        public string $token,
    ) {}

    public static function generate(string $scope, string $webhookId): self
    {
        return new self($scope, $webhookId, bin2hex(random_bytes(16)));
    }
}
