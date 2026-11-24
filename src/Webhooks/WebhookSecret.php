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
 * Do not serialize this object: PHP `serialize` / `var_export` / `(array)` can
 * expose the raw key. Persist a reference (e.g. a secret id) and re-parse, or
 * call {@see serialized()} only when writing the canonical `whsec_` string to a
 * secrets store. {@see __serialize()}, {@see __unserialize()}, and
 * {@see __set_state()} always throw. Note: `var_export()` dumps and `(array)`
 * casts cannot be intercepted by magic methods — never dump this object.
 *
 * @api
 */
final class WebhookSecret
{
    private const string PREFIX = 'whsec_';

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
        self::assertKeyLength($byteLength);

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

        // An empty value is refused by fromBase64Payload() with its own message.
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
     * @return never
     *
     * @throws WebhookException always — persist a secret id / {@see serialized()}, never this object
     */
    public function __serialize(): array
    {
        throw new WebhookException('WebhookSecret must not be serialized; persist a reference or the whsec_ string.');
    }

    /**
     * @param  array<mixed>  $data
     * @return never
     *
     * @throws WebhookException always
     */
    public function __unserialize(array $data): void
    {
        throw new WebhookException('WebhookSecret must not be unserialized; re-parse a whsec_ string instead.');
    }

    /**
     * @param  array<mixed>  $properties
     * @return never
     *
     * @throws WebhookException always
     */
    public static function __set_state(array $properties): self
    {
        throw new WebhookException('WebhookSecret must not be reconstructed via var_export; re-parse a whsec_ string instead.');
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
        self::assertKeyLength($length);

        return new self($decoded);
    }

    /**
     * Standard Webhooks keys are 24 to 64 bytes. A method rather than two
     * constants: a constant's line has no coverage, so mutation testing
     * could not check the bounds.
     *
     * @phpstan-assert int<24, 64> $byteLength
     *
     * @throws WebhookException
     */
    private static function assertKeyLength(int $byteLength): void
    {
        if ($byteLength < 24 || $byteLength > 64) {
            throw new WebhookException('Webhook secret length must be between 24 and 64 bytes.');
        }
    }
}
