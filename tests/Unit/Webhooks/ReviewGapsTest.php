<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Events\Dispatcher;
use Qiick\Outbound\Events\WebhookSignedEvent;
use Qiick\Outbound\Testing\FakeSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\WebhookException;
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
        expect($idStore->claim('endpoint-1', 'msg_1', 300))->toBeTrue()
            ->and($idStore->claim('endpoint-1', 'msg_1', 300))->toBeFalse();

        $idStore->release('endpoint-1', 'msg_1');

        expect($idStore->claim('endpoint-1', 'msg_1', 300))->toBeTrue()
            ->and($idStore->claim('endpoint-2', 'msg_1', 300))->toBeTrue();

        // Releasing one scope leaves the other claimed.
        $idStore->release('endpoint-2', 'msg_1');

        expect($idStore->claim('endpoint-1', 'msg_1', 300))->toBeFalse();
    }
});

it('releases an id never claimed without complaint', function (): void {
    $store = new CacheSeenWebhookIdStore(new CacheRepository(new ArrayStore), allowArrayStore: true);
    $store->release('endpoint-1', 'msg_never');

    expect($store->claim('endpoint-1', 'msg_never', 300))->toBeTrue();
});

it('fails closed when the store cannot release', function (): void {
    $failingStore = new class extends ArrayStore
    {
        public function forget($key): bool
        {
            throw new RuntimeException('cache down');
        }
    };

    (new CacheSeenWebhookIdStore(new CacheRepository($failingStore), allowArrayStore: true))->release('endpoint-1', 'msg_1');
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
