<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

/**
 * A webhook body was signed (design §3.5, standards review M13): for audit
 * and metrics. Ids and counts only, never a secret or the body.
 *
 * @api
 */
final readonly class WebhookSignedEvent
{
    public function __construct(
        public string $webhookId,
        public int $webhookTimestamp,
        public int $signatureCount,
        public int $payloadBytes,
    ) {}
}
