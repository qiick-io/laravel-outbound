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
     * The id is checked here, at dispatch, rather than at every signing: it
     * is the `webhook-id` (no `.`, which separates the signed fields; no
     * control characters) and the stored `event_id` (at most 191 bytes). The
     * type follows Standard Webhooks "Event types": dot-separated segments of
     * letters, digits and underscores (standards review S3).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WebhookException when the id or the type is not valid
     */
    public function __construct(
        public string $id,
        public string $type,
        public DateTimeImmutable $timestamp,
        public array $data,
    ) {
        StandardWebhookSigner::assertValidWebhookId($id);

        if (strlen($id) > 191) {
            throw new WebhookException('Webhook id must be at most 191 bytes.');
        }

        if (preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D', $type) !== 1) {
            throw new WebhookException('Webhook event type must be dot-separated segments of letters, digits and underscores.');
        }
    }

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
