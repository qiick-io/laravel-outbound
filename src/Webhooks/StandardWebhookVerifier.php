<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use DateTimeImmutable;
use Throwable;

/**
 * Symmetric Standard Webhooks `v1` verifier over exact raw body bytes.
 *
 * Fail closed on non-integer timestamps, replay outside tolerance (both
 * directions), oversize payload, empty/oversized secret lists, too many
 * signature tokens, or no `hash_equals` match among `v1` candidates.
 *
 * Timestamp tolerance is not id-dedupe: pass a {@see SeenWebhookIdStore} (or
 * dedupe in the product). After a signature match the verifier atomically
 * {@see SeenWebhookIdStore::claim()}s `(scope, webhook-id)` until
 * `timestamp + tolerance + 1` (holds through the last second the timestamp
 * check would still accept, including sender clock skew).
 *
 * @api
 */
final class StandardWebhookVerifier
{
    public function __construct(
        private readonly int $replayToleranceSeconds = 300,
        private readonly int $maxPayloadBytes = 1_048_576,
        private readonly int $maxSignatures = 8,
        private readonly int $maxSecrets = 4,
        private readonly ?SeenWebhookIdStore $seenIds = null,
    ) {}

    /**
     * @param  list<WebhookSecret>  $secrets
     * @param  non-empty-string  $dedupeScope  MUST identify the verifying secret or
     *                                         endpoint — sharing the default across
     *                                         tenants collides claims on the same id
     * @return WebhookIdClaim|null the claim on the webhook id, which only this
     *                             request can release; null without a store
     *
     * @throws WebhookException when verification fails
     */
    public function verify(
        #[\SensitiveParameter]
        string $rawBody,
        string $webhookId,
        string $webhookTimestamp,
        string $webhookSignatureHeader,
        array $secrets,
        DateTimeImmutable $now,
        string $dedupeScope = 'default',
    ): ?WebhookIdClaim {
        StandardWebhookSigner::assertValidWebhookId($webhookId);

        if ($dedupeScope === '') {
            throw new WebhookException('Webhook dedupe scope must not be empty.');
        }

        if (strlen($rawBody) > $this->maxPayloadBytes) {
            throw new WebhookException('Webhook payload exceeds the configured size limit.');
        }

        $secretCount = count($secrets);
        if ($secretCount === 0) {
            throw new WebhookException('At least one webhook secret is required.');
        }

        if ($secretCount > $this->maxSecrets) {
            throw new WebhookException('Too many webhook secrets.');
        }

        foreach ($secrets as $secret) {
            if (! $secret instanceof WebhookSecret) {
                throw new WebhookException('Webhook secrets must be WebhookSecret instances.');
            }
        }

        $timestamp = $this->parseTimestamp($webhookTimestamp);
        $nowUnix = $now->getTimestamp();
        $delta = abs($nowUnix - $timestamp);

        if ($delta > $this->replayToleranceSeconds) {
            throw new WebhookException('Webhook timestamp is outside the replay tolerance window.');
        }

        $candidates = $this->parseSignatureHeader($webhookSignatureHeader);
        if ($candidates === []) {
            throw new InvalidWebhookSignatureException('No matching webhook signature found.');
        }

        $expectedSignatures = [];
        foreach ($secrets as $secret) {
            $expectedSignatures[] = self::signV1($webhookId, (string) $timestamp, $rawBody, $secret->signingKey());
        }

        $matched = false;
        foreach ($expectedSignatures as $expected) {
            foreach ($candidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    $matched = true;
                }
            }
        }

        if (! $matched) {
            throw new InvalidWebhookSignatureException('No matching webhook signature found.');
        }

        if ($this->seenIds === null) {
            return null;
        }

        // Hold through the last second the timestamp check still accepts (off-by-one safe).
        // After delta <= tolerance, this is always >= 1 (no need to floor).
        $ttl = $timestamp + $this->replayToleranceSeconds - $nowUnix + 1;

        $claim = WebhookIdClaim::generate($dedupeScope, $webhookId);

        try {
            $claimed = $this->seenIds->claim($claim, $ttl);
        } catch (WebhookException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WebhookIdStoreFailedException('Webhook id dedupe store failed.', previous: $e);
        }

        if (! $claimed) {
            throw new DuplicateWebhookIdException('Webhook id has already been processed.');
        }

        return $claim;
    }

    /**
     * @return list<string> Full `v1,<base64>` tokens
     *
     * @throws WebhookException
     */
    private function parseSignatureHeader(string $header): array
    {
        $tokens = preg_split('/\s+/', $header, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            throw new WebhookException('Webhook signature header must not be empty.');
        }

        if (count($tokens) > $this->maxSignatures) {
            throw new WebhookException('Too many webhook signatures.');
        }

        $candidates = [];

        foreach ($tokens as $token) {
            // Split at the first comma only: a scheme this verifier does not
            // know may carry commas in its signature, and is skipped.
            $parts = explode(',', $token, 2);
            if (count($parts) !== 2) {
                throw new WebhookException('Webhook signature header is malformed.');
            }

            [$version, $signature] = $parts;

            if ($version === '') {
                throw new WebhookException('Webhook signature header is malformed.');
            }

            if ($version !== 'v1') {
                continue;
            }

            if ($signature === '' || base64_decode($signature, true) === false) {
                throw new WebhookException('Webhook signature header is malformed.');
            }

            $candidates[] = 'v1,'.$signature;
        }

        return $candidates;
    }

    /**
     * @throws WebhookException
     */
    private function parseTimestamp(string $webhookTimestamp): int
    {
        // Integer unix-seconds string only — refuse floats / signs / whitespace
        // (and the empty string: ctype_digit('') is false).
        if (! ctype_digit($webhookTimestamp)) {
            throw new WebhookException('Webhook timestamp must be an integer unix second.');
        }

        return (int) $webhookTimestamp;
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
