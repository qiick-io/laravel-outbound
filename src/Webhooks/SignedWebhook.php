<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * Exact wire body plus Standard Webhooks headers after signing.
 *
 * @api
 */
final readonly class SignedWebhook
{
    /**
     * @param  array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}  $headers
     */
    public function __construct(
        public string $rawBody,
        public array $headers,
    ) {}
}
