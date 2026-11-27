<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Qiick\Outbound\Events\WebhookSignedEvent;

/**
 * Symmetric Standard Webhooks `v1` signer over exact raw body bytes.
 *
 * Content: `{msg_id}.{timestamp}.{rawBody}`. Header signatures are
 * space-separated `v1,<base64>` entries, one per secret (rotation).
 *
 * @api
 */
final class StandardWebhookSigner
{
    /**
     * @param  Dispatcher|null  $events  receives {@see WebhookSignedEvent} after each signing
     *
     * @internal Resolve the production service from the container.
     */
    public function __construct(
        private readonly int $maxPayloadBytes = 1_048_576,
        private readonly int $maxSecrets = 4,
        private readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Standard Webhooks "Signature scheme": the id and the timestamp must not
     * include a `.`, which separates the signed fields (standards review M4).
     *
     * @throws WebhookException when the id is empty or has a dot or a control character
     */
    public static function assertValidWebhookId(string $msgId): void
    {
        if ($msgId === '') {
            throw new WebhookException('Webhook id must not be empty.');
        }

        if (str_contains($msgId, '.')) {
            throw new WebhookException('Webhook id must not contain a dot.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $msgId) === 1) {
            throw new WebhookException('Webhook id must not contain control characters.');
        }
    }

    /**
     * @param  list<WebhookSecret>  $secrets
     *
     * @throws WebhookException on empty/oversized secrets list, empty secret,
     *                          oversize body, or invalid inputs
     */
    public function sign(
        string $msgId,
        int $timestampUnix,
        #[\SensitiveParameter]
        string $rawBody,
        array $secrets,
    ): SignedWebhook {
        self::assertValidWebhookId($msgId);

        if ($timestampUnix <= 0) {
            throw new WebhookException('Webhook timestamp must be a positive unix second.');
        }

        $secretCount = count($secrets);
        if ($secretCount === 0) {
            throw new WebhookException('At least one webhook secret is required.');
        }

        if ($secretCount > $this->maxSecrets) {
            throw new WebhookException('Too many webhook secrets.');
        }

        if (strlen($rawBody) > $this->maxPayloadBytes) {
            throw new WebhookException('Webhook payload exceeds the configured size limit.');
        }

        $timestamp = (string) $timestampUnix;
        $signatures = [];

        foreach ($secrets as $secret) {
            if (! $secret instanceof WebhookSecret) {
                throw new WebhookException('Webhook secrets must be WebhookSecret instances.');
            }

            $signatures[] = self::signV1($msgId, $timestamp, $rawBody, $secret->signingKey());
        }

        // Ids and counts only: never a secret or the body (design §3.5).
        $this->events?->dispatch(new WebhookSignedEvent($msgId, $timestampUnix, $secretCount, strlen($rawBody)));

        return new SignedWebhook(
            rawBody: $rawBody,
            headers: [
                'webhook-id' => $msgId,
                'webhook-timestamp' => $timestamp,
                'webhook-signature' => implode(' ', $signatures),
            ],
        );
    }

    /**
     * @param  non-empty-string  $key
     */
    private static function signV1(
        string $msgId,
        string $timestamp,
        #[\SensitiveParameter]
        string $rawBody,
        #[\SensitiveParameter]
        string $key,
    ): string {
        $toSign = $msgId.'.'.$timestamp.'.'.$rawBody;
        $digest = hash_hmac('sha256', $toSign, $key, true);

        return 'v1,'.base64_encode($digest);
    }
}
