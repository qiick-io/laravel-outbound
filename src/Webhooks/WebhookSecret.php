<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * Symmetric Standard Webhooks signing secret (`whsec_` + base64, 24–64 raw bytes).
 *
 * Secrets are never exposed via {@see __debugInfo()}, string casts, or exception
 * messages. Prefer {@see parse()}; {@see parseAllowingLegacyRaw()} is for
 * migration only when `outbound.webhooks.allow_raw_legacy_secret` is true.
 *
 * @api
 */
final class WebhookSecret
{
    private const string PREFIX = 'whsec_';

    private const int MIN_BYTES = 24;

    private const int MAX_BYTES = 64;

    /**
     * @param  non-empty-string  $key  Raw HMAC key bytes (24–64).
     */
    private function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
    ) {}

    /**
     * @throws WebhookException when $byteLength is outside 24–64
     */
    public static function generate(int $byteLength = 32): self
    {
        if ($byteLength < self::MIN_BYTES || $byteLength > self::MAX_BYTES) {
            throw new WebhookException('Webhook secret length must be between 24 and 64 bytes.');
        }

        return new self(random_bytes($byteLength));
    }

    /**
     * Parse a `whsec_`-prefixed Standard Webhooks secret.
     *
     * @throws WebhookException on missing prefix, bad base64, or length outside 24–64
     */
    public static function parse(
        #[\SensitiveParameter]
        string $serialized,
    ): self {
        if (! str_starts_with($serialized, self::PREFIX)) {
            throw new WebhookException('Webhook secret must use the whsec_ prefix.');
        }

        $encoded = substr($serialized, strlen(self::PREFIX));

        return self::fromBase64Payload($encoded);
    }

    /**
     * Parse `whsec_` secrets, or (when $allowRaw) base64 without the prefix.
     * Off by default — products enable only during legacy migration.
     *
     * @throws WebhookException when the secret is invalid or raw is disallowed
     */
    public static function parseAllowingLegacyRaw(
        #[\SensitiveParameter]
        string $serialized,
        bool $allowRaw,
    ): self {
        if (str_starts_with($serialized, self::PREFIX)) {
            return self::parse($serialized);
        }

        if (! $allowRaw) {
            throw new WebhookException('Webhook secret must use the whsec_ prefix.');
        }

        // Empty falls through to fromBase64Payload with the same message.
        if ($serialized === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            throw new WebhookException('Webhook secret must not be empty.');
        }

        return self::fromBase64Payload($serialized);
    }

    /**
     * Raw HMAC key bytes.
     *
     * @return non-empty-string
     */
    public function signingKey(): string
    {
        return $this->key;
    }

    /**
     * Canonical `whsec_` + base64 serialization.
     */
    public function serialized(): string
    {
        return self::PREFIX.base64_encode($this->key);
    }

    /**
     * @return array{prefix: string, byteLength: int, key: string}
     */
    public function __debugInfo(): array
    {
        return [
            'prefix' => self::PREFIX,
            'byteLength' => strlen($this->key),
            'key' => '[REDACTED]',
        ];
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    /**
     * @throws WebhookException
     */
    private static function fromBase64Payload(
        #[\SensitiveParameter]
        string $encoded,
    ): self {
        if ($encoded === '') {
            throw new WebhookException('Webhook secret must not be empty.');
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new WebhookException('Webhook secret is not valid base64.');
        }

        $length = strlen($decoded);
        if ($length < self::MIN_BYTES || $length > self::MAX_BYTES) {
            throw new WebhookException('Webhook secret length must be between 24 and 64 bytes.');
        }

        return new self($decoded);
    }
}
