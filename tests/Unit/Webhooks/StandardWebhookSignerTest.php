<?php

declare(strict_types=1);

use Qiick\Outbound\Tests\Fixtures\StandardWebhooks\OfficialVectors;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

it('matches the official Standard Webhooks sign vector', function (): void {
    $signer = new StandardWebhookSigner;
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);

    $signed = $signer->sign(
        OfficialVectors::SIGN_MSG_ID,
        OfficialVectors::SIGN_TIMESTAMP,
        OfficialVectors::SIGN_PAYLOAD,
        [$secret],
    );

    expect($signed->rawBody)->toBe(OfficialVectors::SIGN_PAYLOAD)
        ->and($signed->headers['webhook-id'])->toBe(OfficialVectors::SIGN_MSG_ID)
        ->and($signed->headers['webhook-timestamp'])->toBe((string) OfficialVectors::SIGN_TIMESTAMP)
        ->and($signed->headers['webhook-signature'])->toBe(OfficialVectors::SIGN_EXPECTED);
});

it('emits space-separated v1 signatures for secret rotation', function (): void {
    $signer = new StandardWebhookSigner;
    $a = WebhookSecret::generate(32);
    $b = WebhookSecret::generate(32);

    $signed = $signer->sign('msg_1', 1_700_000_000, '{"ok":true}', [$a, $b]);
    $parts = explode(' ', $signed->headers['webhook-signature']);

    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toStartWith('v1,')
        ->and($parts[1])->toStartWith('v1,')
        ->and($parts[0])->not->toBe($parts[1]);
});

it('refuses an empty secret list', function (): void {
    expect(fn () => (new StandardWebhookSigner)->sign('msg_1', 1, '{}', []))
        ->toThrow(WebhookException::class);
});

it('refuses more secrets than the configured max', function (): void {
    $secrets = [
        WebhookSecret::generate(24),
        WebhookSecret::generate(24),
        WebhookSecret::generate(24),
    ];

    expect(fn () => (new StandardWebhookSigner(maxSecrets: 2))->sign('msg_1', 1, '{}', $secrets))
        ->toThrow(WebhookException::class);
});

it('refuses payloads larger than maxPayloadBytes before HMAC', function (): void {
    $secret = WebhookSecret::generate(24);
    $body = str_repeat('x', 11);

    expect(fn () => (new StandardWebhookSigner(maxPayloadBytes: 10))->sign('msg_1', 1, $body, [$secret]))
        ->toThrow(WebhookException::class);
});

it('refuses an empty message id and non-positive timestamps', function (): void {
    $secret = WebhookSecret::generate(24);

    expect(fn () => (new StandardWebhookSigner)->sign('', 1, '{}', [$secret]))
        ->toThrow(WebhookException::class)
        ->and(fn () => (new StandardWebhookSigner)->sign('msg_1', -1, '{}', [$secret]))
        ->toThrow(WebhookException::class)
        ->and(fn () => (new StandardWebhookSigner)->sign('msg_1', 0, '{}', [$secret]))
        ->toThrow(WebhookException::class, 'Webhook timestamp must be a positive unix second.');
});

it('refuses message ids with CR, LF, or other control characters', function (string $msgId): void {
    $secret = WebhookSecret::generate(24);

    expect(fn () => (new StandardWebhookSigner)->sign($msgId, 1, '{}', [$secret]))
        ->toThrow(WebhookException::class, 'Webhook id must not contain control characters.');
})->with([
    "msg\n1",
    "msg\r1",
    "msg\0id",
]);

it('signs the exact raw bytes — whitespace changes the signature', function (): void {
    $signer = new StandardWebhookSigner;
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);

    $a = $signer->sign(OfficialVectors::SIGN_MSG_ID, OfficialVectors::SIGN_TIMESTAMP, '{"a":1}', [$secret]);
    $b = $signer->sign(OfficialVectors::SIGN_MSG_ID, OfficialVectors::SIGN_TIMESTAMP, '{"a": 1}', [$secret]);

    expect($a->headers['webhook-signature'])->not->toBe($b->headers['webhook-signature']);
});

it('allows exact maxSecrets and exact maxPayloadBytes', function (): void {
    $secrets = [WebhookSecret::generate(24), WebhookSecret::generate(24)];
    $signer = new StandardWebhookSigner(maxPayloadBytes: 10, maxSecrets: 2);
    $body = str_repeat('x', 10);

    $signed = $signer->sign('msg_0', 1, $body, $secrets);

    expect($signed->headers['webhook-timestamp'])->toBe('1')
        ->and($signed->headers['webhook-signature'])->toContain(' ');
});

it('refuses a non-WebhookSecret entry in the secrets list', function (): void {
    expect(fn () => (new StandardWebhookSigner)->sign('msg_1', 1, '{}', ['not-a-secret']))
        ->toThrow(WebhookException::class);
});

it('signs up to 1 MiB and 4 secrets by default, and refuses one more', function (): void {
    $signer = new StandardWebhookSigner;
    $secrets = [WebhookSecret::generate(), WebhookSecret::generate(), WebhookSecret::generate(), WebhookSecret::generate()];

    expect($signer->sign('msg_1', 1_700_000_000, str_repeat('a', 1_048_576), $secrets)->headers['webhook-signature'])->toContain('v1,')
        ->and(fn () => $signer->sign('msg_1', 1_700_000_000, str_repeat('a', 1_048_577), [$secrets[0]]))->toThrow(WebhookException::class, 'Webhook payload exceeds the configured size limit.')
        ->and(fn () => $signer->sign('msg_1', 1_700_000_000, '{}', [...$secrets, WebhookSecret::generate()]))->toThrow(WebhookException::class, 'Too many webhook secrets.');
});
