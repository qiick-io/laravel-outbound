<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Events\Dispatcher;
use Qiick\Outbound\Events\WebhookSignedEvent;
use Qiick\Outbound\Testing\FakeSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\DuplicateWebhookIdException;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookIdClaim;
use Qiick\Outbound\Webhooks\WebhookIdStoreFailedException;
use Qiick\Outbound\Webhooks\WebhookMessage;
use Qiick\Outbound\Webhooks\WebhookSecret;

/*
 * Standards review of 2026-11-22: M4, M14, S3 and the WebhookSigned half of
 * M13.
 */

it('refuses a webhook id with a dot, which separates the signed fields (M4)', function (): void {
    (new StandardWebhookSigner)->sign('msg.1', 1_700_000_000, '{}', [WebhookSecret::generate()]);
})->throws(WebhookException::class, 'Webhook id must not contain a dot.');

it('names the reason when it refuses a message id', function (string $id, string $message): void {
    expect(fn () => new WebhookMessage($id, 'invoice.paid', new DateTimeImmutable, []))->toThrow(WebhookException::class, $message);
})->with([
    ['', 'Webhook id must not be empty.'],
    ['msg.1', 'Webhook id must not contain a dot.'],
    ["msg\x7f1", 'Webhook id must not contain control characters.'],
    [str_repeat('a', 192), 'Webhook id must be at most 191 bytes.'],
]);

it('accepts an id of 191 bytes', function (): void {
    expect((new WebhookMessage(str_repeat('a', 191), 'invoice.paid', new DateTimeImmutable, []))->id)->toHaveLength(191);
});

it('accepts event types of dot-separated letter, digit and underscore segments (S3)', function (string $type): void {
    expect((new WebhookMessage('msg_1', $type, new DateTimeImmutable, []))->type)->toBe($type);
})->with(['invoice.paid', 'user', 'v2_invoice.line_item.created', 'A.b_9']);

it('refuses any other event type', function (string $type): void {
    new WebhookMessage('msg_1', $type, new DateTimeImmutable, []);
})->with(['', '.invoice', 'invoice.', 'invoice..paid', 'invoice-paid', 'invoice paid', 'invoice.paid/x', 'facture.payée', "invoice.paid\n"])
    ->throws(WebhookException::class, 'Webhook event type must be dot-separated segments of letters, digits and underscores.');

it('gives a claimed webhook id back, so the consumer can process it again (M14)', function (): void {
    $store = new CacheSeenWebhookIdStore(new CacheRepository(new ArrayStore), allowArrayStore: true);
    $fake = new FakeSeenWebhookIdStore;

    foreach ([$store, $fake] as $idStore) {
        $firstClaim = WebhookIdClaim::generate('endpoint-1', 'msg_1');
        $otherScopeClaim = WebhookIdClaim::generate('endpoint-2', 'msg_1');

        expect($idStore->claim($firstClaim, 300))->toBeTrue()
            ->and($idStore->claim(WebhookIdClaim::generate('endpoint-1', 'msg_1'), 300))->toBeFalse();

        $idStore->release($firstClaim);

        expect($idStore->claim(WebhookIdClaim::generate('endpoint-1', 'msg_1'), 300))->toBeTrue()
            ->and($idStore->claim($otherScopeClaim, 300))->toBeTrue();

        // Releasing one scope leaves the other claimed.
        $idStore->release($otherScopeClaim);

        expect($idStore->claim(WebhookIdClaim::generate('endpoint-1', 'msg_1'), 300))->toBeFalse();
    }
});

it('releases only the claim it holds, never one another request won', function (): void {
    $store = new CacheSeenWebhookIdStore(new CacheRepository(new ArrayStore), allowArrayStore: true);
    $fake = new FakeSeenWebhookIdStore;

    foreach ([$store, $fake] as $idStore) {
        $winningClaim = WebhookIdClaim::generate('endpoint-1', 'msg_1');
        $losingClaim = WebhookIdClaim::generate('endpoint-1', 'msg_1');

        expect($idStore->claim($winningClaim, 300))->toBeTrue()
            ->and($idStore->claim($losingClaim, 300))->toBeFalse();

        $idStore->release($losingClaim);

        expect($idStore->claim(WebhookIdClaim::generate('endpoint-1', 'msg_1'), 300))->toBeFalse();
    }
});

it('returns the claim from verify, and gives a duplicate none to release', function (): void {
    $idStore = new FakeSeenWebhookIdStore(static fn (): int => 1_700_000_000);
    $verifier = new StandardWebhookVerifier(seenIds: $idStore);
    $secret = WebhookSecret::generate();
    $signed = (new StandardWebhookSigner)->sign('msg_1', 1_700_000_000, '{}', [$secret]);
    $verify = fn (): ?WebhookIdClaim => $verifier->verify($signed->rawBody, 'msg_1', '1700000000', $signed->headers['webhook-signature'], [$secret], new DateTimeImmutable('@1700000000'), 'endpoint-1');

    $claim = $verify();

    expect($claim?->scope)->toBe('endpoint-1')
        ->and($claim?->webhookId)->toBe('msg_1')
        ->and($verify)->toThrow(DuplicateWebhookIdException::class);

    $idStore->release($claim ?? throw new LogicException('no claim'));

    expect($verify())->toBeInstanceOf(WebhookIdClaim::class);
});

it('returns no claim when the verifier has no store', function (): void {
    $secret = WebhookSecret::generate();
    $signed = (new StandardWebhookSigner)->sign('msg_1', 1_700_000_000, '{}', [$secret]);

    expect((new StandardWebhookVerifier)->verify($signed->rawBody, 'msg_1', '1700000000', $signed->headers['webhook-signature'], [$secret], new DateTimeImmutable('@1700000000'), 'test-scope'))->toBeNull();
});

it('draws a fresh token for every claim', function (): void {
    expect(WebhookIdClaim::generate('endpoint-1', 'msg_1')->token)
        ->toMatch('/^[0-9a-f]{32}$/')
        ->not->toBe(WebhookIdClaim::generate('endpoint-1', 'msg_1')->token);
});

it('refuses on verify the webhook ids the signer refuses (M4)', function (string $webhookId, string $message): void {
    $secret = WebhookSecret::generate();

    expect(fn () => (new StandardWebhookVerifier)->verify('{}', $webhookId, '1700000000', 'v1,x', [$secret], new DateTimeImmutable('@1700000000'), 'test-scope'))
        ->toThrow(WebhookException::class, $message);
})->with([
    ['msg.1', 'Webhook id must not contain a dot.'],
    ['', 'Webhook id must not be empty.'],
    ["msg\n1", 'Webhook id must not contain control characters.'],
]);

it('refuses an event type over 191 bytes, the stored event_type', function (): void {
    expect((new WebhookMessage('msg_1', str_repeat('a', 191), new DateTimeImmutable, []))->type)->toHaveLength(191)
        ->and(fn () => new WebhookMessage('msg_1', str_repeat('a', 192), new DateTimeImmutable, []))
        ->toThrow(WebhookException::class, 'Webhook event type must be at most 191 bytes.');
});

it('refuses a message id that is not valid UTF-8, which the event_id column rejects', function (): void {
    new WebhookMessage("msg_\xB1", 'invoice.paid', new DateTimeImmutable, []);
})->throws(WebhookException::class, 'Webhook id must be valid UTF-8.');

it('releases an id never claimed without complaint', function (): void {
    $store = new CacheSeenWebhookIdStore(new CacheRepository(new ArrayStore), allowArrayStore: true);
    $store->release(WebhookIdClaim::generate('endpoint-1', 'msg_never'));

    expect($store->claim(WebhookIdClaim::generate('endpoint-1', 'msg_never'), 300))->toBeTrue();
});

it('fails closed when the store cannot release', function (): void {
    $failingStore = new class extends ArrayStore
    {
        public function restoreLock($name, $owner): Lock
        {
            throw new RuntimeException('cache down');
        }
    };

    (new CacheSeenWebhookIdStore(new CacheRepository($failingStore), allowArrayStore: true))->release(WebhookIdClaim::generate('endpoint-1', 'msg_1'));
})->throws(WebhookIdStoreFailedException::class, 'Webhook id dedupe store failed.');

it('announces each signature without secrets or the body (M13)', function (): void {
    $events = new Dispatcher;
    $seen = [];
    $events->listen(WebhookSignedEvent::class, function (WebhookSignedEvent $event) use (&$seen): void {
        $seen[] = $event;
    });
    $secret = WebhookSecret::generate();

    (new StandardWebhookSigner(events: $events))->sign('msg_1', 1_700_000_000, '{"secret":"body"}', [$secret, WebhookSecret::generate()]);

    expect($seen)->toHaveCount(1)
        ->and($seen[0]->webhookId)->toBe('msg_1')
        ->and($seen[0]->webhookTimestamp)->toBe(1_700_000_000)
        ->and($seen[0]->signatureCount)->toBe(2)
        ->and($seen[0]->payloadBytes)->toBe(17)
        ->and(serialize($seen[0]))->not->toContain('body')->not->toContain(base64_encode($secret->signingKey()));
});

it('makes every caller name its dedupe scope, so tenants never share one', function (): void {
    $dedupeScope = new ReflectionParameter([StandardWebhookVerifier::class, 'verify'], 'dedupeScope');

    expect($dedupeScope->isOptional())->toBeFalse();
});
