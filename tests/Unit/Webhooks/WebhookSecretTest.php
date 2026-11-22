<?php

declare(strict_types=1);

use Qiick\Outbound\Tests\Fixtures\StandardWebhooks\OfficialVectors;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

it('generates a whsec_ secret of the requested byte length', function (): void {
    $secret = WebhookSecret::generate(32);

    expect($secret->serialized())->toStartWith('whsec_')
        ->and(strlen($secret->signingKey()))->toBe(32);

    $roundTrip = WebhookSecret::parse($secret->serialized());
    expect($roundTrip->signingKey())->toBe($secret->signingKey());
});

it('refuses generate lengths outside 24–64', function (int $length): void {
    expect(fn () => WebhookSecret::generate($length))->toThrow(WebhookException::class);
})->with([23, 65, 0, -1]);

it('parses the official whsec_ secret', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);

    expect(strlen($secret->signingKey()))->toBe(24)
        ->and($secret->serialized())->toBe(OfficialVectors::SIGN_SECRET);
});

it('refuses secrets without the whsec_ prefix', function (): void {
    expect(fn () => WebhookSecret::parse(OfficialVectors::TEST_PAYLOAD_SECRET_B64))
        ->toThrow(WebhookException::class);
});

it('accepts legacy base64 without prefix only when allowRaw is true', function (): void {
    expect(fn () => WebhookSecret::parseAllowingLegacyRaw(OfficialVectors::TEST_PAYLOAD_SECRET_B64, false))
        ->toThrow(WebhookException::class);

    $secret = WebhookSecret::parseAllowingLegacyRaw(OfficialVectors::TEST_PAYLOAD_SECRET_B64, true);
    expect($secret->serialized())->toBe(OfficialVectors::SIGN_SECRET);
});

it('still parses whsec_ via parseAllowingLegacyRaw when allowRaw is false', function (): void {
    $secret = WebhookSecret::parseAllowingLegacyRaw(OfficialVectors::SIGN_SECRET, false);
    expect($secret->serialized())->toBe(OfficialVectors::SIGN_SECRET);
});

it('refuses empty, invalid base64, and out-of-range decoded lengths', function (string $serialized, string $message): void {
    expect(fn () => WebhookSecret::parse($serialized))
        ->toThrow(WebhookException::class, $message);
})->with([
    'empty after prefix' => ['whsec_', 'Webhook secret must not be empty.'],
    'bad base64' => ['whsec_!!!', 'Webhook secret is not valid base64.'],
    'too short' => ['whsec_'.base64_encode(str_repeat('a', 23)), 'Webhook secret length must be between 24 and 64 bytes.'],
    'too long' => ['whsec_'.base64_encode(str_repeat('a', 65)), 'Webhook secret length must be between 24 and 64 bytes.'],
]);

it('redacts secrets from debug info and string casts', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $debug = print_r($secret, true);

    expect((string) $secret)->toBe('[REDACTED]')
        ->and($debug)->not->toContain($secret->signingKey())
        ->and($debug)->not->toContain(OfficialVectors::SIGN_SECRET)
        ->and($debug)->toContain('[REDACTED]')
        ->and($secret->__debugInfo()['key'])->toBe('[REDACTED]');
});

it('never puts the secret material into exception messages', function (): void {
    $leaky = OfficialVectors::SIGN_SECRET;

    try {
        WebhookSecret::parse('whsec_!!!'.$leaky);
        expect(false)->toBeTrue();
    } catch (WebhookException $e) {
        expect($e->getMessage())->not->toContain($leaky)
            ->and($e->getMessage())->not->toContain('MfKQ9r8');
    }
});

it('allows generate and parse at the exact 24 and 64 byte boundaries', function (): void {
    foreach ([24, 64] as $length) {
        $generated = WebhookSecret::generate($length);
        expect(strlen($generated->signingKey()))->toBe($length);

        $parsed = WebhookSecret::parse($generated->serialized());
        expect(strlen($parsed->signingKey()))->toBe($length);
    }
});

it('refuses an empty legacy raw secret even when allowRaw is true', function (): void {
    expect(fn () => WebhookSecret::parseAllowingLegacyRaw('', true))
        ->toThrow(WebhookException::class, 'Webhook secret must not be empty.');
});

it('exposes only redacted keys in debug info', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $info = $secret->__debugInfo();

    expect($info)->toHaveKeys(['prefix', 'byteLength', 'key'])
        ->and($info['prefix'])->toBe('whsec_')
        ->and($info['byteLength'])->toBe(24)
        ->and($info['key'])->toBe('[REDACTED]')
        ->and(count($info))->toBe(3);
});

it('refuses serialize, unserialize, and var_export reconstruction', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);

    expect(fn () => serialize($secret))
        ->toThrow(WebhookException::class, 'WebhookSecret must not be serialized')
        ->and(fn () => $secret->__unserialize(['key' => 'x']))
        ->toThrow(WebhookException::class, 'WebhookSecret must not be unserialized')
        ->and(fn () => WebhookSecret::__set_state(['key' => 'x']))
        ->toThrow(WebhookException::class, 'WebhookSecret must not be reconstructed via var_export');
});

it('refuses unserializing a forged payload that would bypass length checks', function (): void {
    // Object payload that would call __unserialize with a 1-byte key (bypass length).
    $forged = 'O:'.strlen(WebhookSecret::class).':"'.WebhookSecret::class.'":1:{s:3:"key";s:1:"x";}';

    expect(fn () => unserialize($forged))
        ->toThrow(WebhookException::class, 'WebhookSecret must not be unserialized');
});
