<?php

declare(strict_types=1);

use Qiick\Outbound\Testing\FakeSeenWebhookIdStore;
use Qiick\Outbound\Tests\Fixtures\StandardWebhooks\OfficialVectors;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

function swVerifier(
    int $tolerance = 300,
    int $maxPayload = 1_048_576,
    int $maxSigs = 8,
    int $maxSecrets = 4,
    ?SeenWebhookIdStore $seenIds = null,
): StandardWebhookVerifier {
    return new StandardWebhookVerifier($tolerance, $maxPayload, $maxSigs, $maxSecrets, $seenIds);
}

it('verifies the official sign vector at the vector timestamp', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);

    expect(fn () => swVerifier()->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        OfficialVectors::SIGN_EXPECTED,
        [$secret],
        $now,
    ))->not->toThrow(WebhookException::class);
});

it('verifies a freshly signed payload within tolerance', function (): void {
    $secret = WebhookSecret::generate(32);
    $now = new DateTimeImmutable('@1700000000');
    $signed = (new StandardWebhookSigner)->sign('msg_abc', 1_700_000_000, '{"n":1}', [$secret]);

    swVerifier()->verify(
        $signed->rawBody,
        $signed->headers['webhook-id'],
        $signed->headers['webhook-timestamp'],
        $signed->headers['webhook-signature'],
        [$secret],
        $now,
    );

    expect(true)->toBeTrue();
});

it('accepts any matching signature among space-separated rotation entries', function (): void {
    $current = WebhookSecret::generate(32);
    $previous = WebhookSecret::generate(32);
    $now = new DateTimeImmutable('@1700000000');

    $withPrevious = (new StandardWebhookSigner)->sign('msg_r', 1_700_000_000, '{"r":1}', [$previous]);
    $header = 'v1,notavalidsignature==== '.$withPrevious->headers['webhook-signature'];

    // "notavalidsignature====" may fail base64 strict — use a valid base64 decoy instead
    $decoy = 'v1,'.base64_encode(str_repeat("\0", 32));
    $header = $decoy.' '.$withPrevious->headers['webhook-signature'];

    swVerifier()->verify(
        $withPrevious->rawBody,
        'msg_r',
        '1700000000',
        $header,
        [$current, $previous],
        $now,
    );

    expect(true)->toBeTrue();
});

it('ignores non-v1 versions while still matching a later v1 signature', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);
    $sig = OfficialVectors::SIGN_EXPECTED;
    $header = 'v2,'.explode(',', $sig, 2)[1].' '.$sig;

    swVerifier()->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        $header,
        [$secret],
        $now,
    );

    expect(true)->toBeTrue();
});

it('refuses timestamps outside tolerance in both directions', function (int $skew): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $ts = OfficialVectors::SIGN_TIMESTAMP;
    $now = new DateTimeImmutable('@'.($ts + $skew));

    expect(fn () => swVerifier(tolerance: 300)->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) $ts,
        OfficialVectors::SIGN_EXPECTED,
        [$secret],
        $now,
    ))->toThrow(WebhookException::class);
})->with([
    'too old' => 301,
    'too new' => -301,
]);

it('accepts timestamps at the exact tolerance boundary', function (int $skew): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $ts = OfficialVectors::SIGN_TIMESTAMP;
    $now = new DateTimeImmutable('@'.($ts + $skew));

    swVerifier(tolerance: 300)->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) $ts,
        OfficialVectors::SIGN_EXPECTED,
        [$secret],
        $now,
    );

    expect(true)->toBeTrue();
})->with([
    'old boundary' => 300,
    'new boundary' => -300,
]);

it('refuses non-integer timestamps', function (string $timestamp): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1700000000');

    expect(fn () => swVerifier()->verify('{}', 'msg_1', $timestamp, 'v1,'.base64_encode('x'), [$secret], $now))
        ->toThrow(WebhookException::class, 'Webhook timestamp must be an integer unix second.');
})->with(['abc', '1.5', '-1', '1e3', ' 1', '']);

it('refuses an oversize payload before comparing signatures', function (): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1');

    expect(fn () => swVerifier(maxPayload: 4)->verify('12345', 'msg_1', '1', 'v1,'.base64_encode('x'), [$secret], $now))
        ->toThrow(WebhookException::class);
});

it('refuses more signature tokens than maxSignatures', function (): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1700000000');
    $token = 'v1,'.base64_encode(str_repeat('a', 32));
    $header = implode(' ', array_fill(0, 3, $token));

    expect(fn () => swVerifier(maxSigs: 2)->verify('{}', 'msg_1', '1700000000', $header, [$secret], $now))
        ->toThrow(WebhookException::class);
});

it('refuses empty and oversized secret lists', function (): void {
    $now = new DateTimeImmutable('@1');
    $secrets = [WebhookSecret::generate(24), WebhookSecret::generate(24), WebhookSecret::generate(24)];

    expect(fn () => swVerifier()->verify('{}', 'msg_1', '1', 'v1,'.base64_encode('x'), [], $now))
        ->toThrow(WebhookException::class, 'At least one webhook secret is required.')
        ->and(fn () => swVerifier(maxSecrets: 2)->verify('{}', 'msg_1', '1', 'v1,'.base64_encode('x'), $secrets, $now))
        ->toThrow(WebhookException::class, 'Too many webhook secrets.');
});

it('accepts exactly maxSecrets secrets when one of them matches', function (): void {
    $matching = WebhookSecret::generate(24);
    $others = [WebhookSecret::generate(24), WebhookSecret::generate(24), WebhookSecret::generate(24)];
    $now = new DateTimeImmutable('@1700000000');
    $signed = (new StandardWebhookSigner)->sign('msg_max', 1_700_000_000, '{"m":1}', [$matching]);

    swVerifier(maxSecrets: 4)->verify(
        $signed->rawBody,
        'msg_max',
        '1700000000',
        $signed->headers['webhook-signature'],
        [...$others, $matching],
        $now,
    );

    expect(true)->toBeTrue();
});

it('refuses a wrong signature and a v2-only header with the correct digest', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);
    $digest = explode(',', OfficialVectors::SIGN_EXPECTED, 2)[1];

    expect(fn () => swVerifier()->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        'v1,'.base64_encode(str_repeat('z', 32)),
        [$secret],
        $now,
    ))->toThrow(WebhookException::class)
        ->and(fn () => swVerifier()->verify(
            OfficialVectors::SIGN_PAYLOAD,
            OfficialVectors::SIGN_MSG_ID,
            (string) OfficialVectors::SIGN_TIMESTAMP,
            'v2,'.$digest,
            [$secret],
            $now,
        ))->toThrow(WebhookException::class);
});

it('refuses malformed signature tokens', function (string $header, string $message): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1');

    expect(fn () => swVerifier()->verify('{}', 'msg_1', '1', $header, [$secret], $now))
        ->toThrow(WebhookException::class, $message);
})->with([
    'no comma' => ['v1only', 'Webhook signature header is malformed.'],
    'empty v1 sig' => ['v1,', 'Webhook signature header is malformed.'],
    'empty version' => [',abc', 'Webhook signature header is malformed.'],
    'bad base64' => ['v1,!!!', 'Webhook signature header is malformed.'],
    'blank header' => ['   ', 'Webhook signature header must not be empty.'],
]);

it('refuses verification when the raw body differs by a single byte', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);

    expect(fn () => swVerifier()->verify(
        OfficialVectors::SIGN_PAYLOAD.' ',
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        OfficialVectors::SIGN_EXPECTED,
        [$secret],
        $now,
    ))->toThrow(WebhookException::class);
});

it('uses hash_equals semantics — a matching signature still wins among decoys', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);
    $decoy = 'v1,'.base64_encode(random_bytes(32));
    $header = $decoy.' '.OfficialVectors::SIGN_EXPECTED.' '.$decoy;

    swVerifier()->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        $header,
        [$secret],
        $now,
    );

    expect(true)->toBeTrue();
});

it('allows exactly maxSignatures tokens and exact payload size', function (): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1700000000');
    $body = str_repeat('y', 8);
    $signed = (new StandardWebhookSigner(maxPayloadBytes: 8))->sign('msg_b', 1_700_000_000, $body, [$secret]);

    $decoy = 'v1,'.base64_encode(str_repeat('z', 32));
    $header = $decoy.' '.$decoy.' '.$signed->headers['webhook-signature'];

    swVerifier(maxPayload: 8, maxSigs: 3)->verify(
        $body,
        'msg_b',
        '1700000000',
        $header,
        [$secret],
        $now,
    );

    expect(true)->toBeTrue();
});

it('refuses a completely empty signature header string', function (): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1');

    expect(fn () => swVerifier()->verify('{}', 'msg_1', '1', '', [$secret], $now))
        ->toThrow(WebhookException::class, 'Webhook signature header must not be empty.');
});

it('refuses a non-WebhookSecret entry during verify', function (): void {
    $now = new DateTimeImmutable('@1');

    expect(fn () => swVerifier()->verify('{}', 'msg_1', '1', 'v1,'.base64_encode('x'), ['nope'], $now))
        ->toThrow(WebhookException::class);
});

it('accepts a padded signature header with surrounding whitespace', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);
    $header = '  '.OfficialVectors::SIGN_EXPECTED.'  ';

    swVerifier()->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        $header,
        [$secret],
        $now,
    );

    expect(true)->toBeTrue();
});

it('refuses an empty webhook id', function (): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1');

    expect(fn () => swVerifier()->verify('{}', '', '1', 'v1,'.base64_encode('x'), [$secret], $now))
        ->toThrow(WebhookException::class, 'Webhook id must not be empty.');
});

it('refuses a malformed base64 token even when a later signature is valid', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);
    $header = 'v1,@@@ '.OfficialVectors::SIGN_EXPECTED;

    expect(fn () => swVerifier()->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        $header,
        [$secret],
        $now,
    ))->toThrow(WebhookException::class, 'Webhook signature header is malformed.');
});

it('refuses a repeated webhook-id via the seen-id store after a successful verify', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);
    $seen = new FakeSeenWebhookIdStore(static fn (): int => OfficialVectors::SIGN_TIMESTAMP);
    $verifier = swVerifier(seenIds: $seen);

    $verifier->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        OfficialVectors::SIGN_EXPECTED,
        [$secret],
        $now,
    );

    expect(fn () => $verifier->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        OfficialVectors::SIGN_EXPECTED,
        [$secret],
        $now,
    ))->toThrow(WebhookException::class, 'Webhook id has already been processed.');
});

it('does not remember a webhook-id when signature verification fails', function (): void {
    $secret = WebhookSecret::parse(OfficialVectors::SIGN_SECRET);
    $now = new DateTimeImmutable('@'.OfficialVectors::SIGN_TIMESTAMP);
    $seen = new FakeSeenWebhookIdStore(static fn (): int => OfficialVectors::SIGN_TIMESTAMP);
    $verifier = swVerifier(seenIds: $seen);

    expect(fn () => $verifier->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        'v1,'.base64_encode(str_repeat('z', 32)),
        [$secret],
        $now,
    ))->toThrow(WebhookException::class);

    $verifier->verify(
        OfficialVectors::SIGN_PAYLOAD,
        OfficialVectors::SIGN_MSG_ID,
        (string) OfficialVectors::SIGN_TIMESTAMP,
        OfficialVectors::SIGN_EXPECTED,
        [$secret],
        $now,
    );

    expect(true)->toBeTrue();
});

it('refuses webhook ids with control characters', function (): void {
    $secret = WebhookSecret::generate(24);
    $now = new DateTimeImmutable('@1');

    expect(fn () => swVerifier()->verify('{}', "msg\nid", '1', 'v1,'.base64_encode('x'), [$secret], $now))
        ->toThrow(WebhookException::class, 'Webhook id must not contain control characters.');
});
