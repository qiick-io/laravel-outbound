<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use DateTimeImmutable;

/**
 * Symmetric Standard Webhooks `v1` verifier over exact raw body bytes.
 *
 * Fail closed on non-integer timestamps, replay outside tolerance (both
 * directions), oversize payload, empty/oversized secret lists, too many
 * signature tokens, or no `hash_equals` match among `v1` candidates.
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
    ) {}

    /**
     * @param  list<WebhookSecret>  $secrets
     *
     * @throws WebhookException when verification fails
     */
    public function verify(
        string $rawBody,
        string $webhookId,
        string $webhookTimestamp,
        string $webhookSignatureHeader,
        array $secrets,
        DateTimeImmutable $now,
    ): void {
        if ($webhookId === '') {
            throw new WebhookException('Webhook id must not be empty.');
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
            throw new WebhookException('No matching webhook signature found.');
        }

        foreach ($candidates as $candidate) {
            foreach ($secrets as $secret) {
                $expected = self::signV1($webhookId, (string) $timestamp, $rawBody, $secret->signingKey());
                if (hash_equals($expected, $candidate)) {
                    return;
                }
            }
        }

        throw new WebhookException('No matching webhook signature found.');
    }

    /**
     * @return list<string> Full `v1,<base64>` tokens
     *
     * @throws WebhookException
     */
    private function parseSignatureHeader(string $header): array
    {
        // Empty falls through to trim/filter with the same empty-header message.
        if ($header === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            throw new WebhookException('Webhook signature header must not be empty.');
        }

        // trim + filter + values: empty tokens from whitespace are equivalent under
        // the empty-header guard; unwrap mutants do not change observable rejects.
        $tokens = preg_split('/\s+/', trim($header)) ?: []; // @pest-mutate-ignore: UnwrapTrim
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== '')); // @pest-mutate-ignore: UnwrapArrayFilter,UnwrapArrayValues,EmptyStringToNotEmpty

        if ($tokens === []) {
            throw new WebhookException('Webhook signature header must not be empty.');
        }

        if (count($tokens) > $this->maxSignatures) {
            throw new WebhookException('Too many webhook signatures.');
        }

        $candidates = [];

        foreach ($tokens as $token) {
            // Limit 2 vs 3 is equivalent: base64 never contains commas.
            $parts = explode(',', $token, 2); // @pest-mutate-ignore: IncrementInteger
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

            // Strict base64 flag: non-strict accept of whitespace-padded alphabet is
            // not distinguishable from malformed rejects in our token grammar.
            if ($signature === '' || base64_decode($signature, true) === false) { // @pest-mutate-ignore: TrueToFalse
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
        // Integer unix-seconds string only — refuse floats / signs / whitespace.
        // Empty is already caught by ! ctype_digit('').
        if ($webhookTimestamp === '' || ! ctype_digit($webhookTimestamp)) { // @pest-mutate-ignore: EmptyStringToNotEmpty
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
        string $rawBody,
        #[\SensitiveParameter]
        string $key,
    ): string {
        $toSign = $msgId.'.'.$timestamp.'.'.$rawBody;
        $digest = hash_hmac('sha256', $toSign, $key, true);

        return 'v1,'.base64_encode($digest);
    }
}
