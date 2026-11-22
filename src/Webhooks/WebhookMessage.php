<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;

/**
 * Standard Webhooks envelope. {@see $id} is the webhook-id header only — it is
 * not embedded in the JSON body. {@see toPayloadJson()} is the only body
 * encoding used for both send and sign.
 *
 * @api
 */
final readonly class WebhookMessage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public DateTimeImmutable $timestamp,
        public array $data,
    ) {}

    /**
     * Compact UTF-8 JSON: `{type, timestamp, data}` with stable key order as
     * constructed. No pretty-print.
     *
     * @throws WebhookException when JSON encoding fails
     */
    public function toPayloadJson(): string
    {
        $payload = [
            'type' => $this->type,
            'timestamp' => $this->timestamp->format(DateTimeInterface::ATOM),
            // Empty data must be `{}`, not `[]` (Standard Webhooks envelope).
            'data' => $this->data === [] ? new \stdClass : $this->data,
        ];

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new WebhookException('Webhook payload could not be encoded as JSON.', 0, $e);
        }
    }
}
