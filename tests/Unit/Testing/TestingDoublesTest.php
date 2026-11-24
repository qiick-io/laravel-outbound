<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Testing\FakeOutboundHttpFactory;
use Qiick\Outbound\Testing\FakeSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\WebhookIdClaim;
use Qiick\Outbound\Webhooks\WebhookIdStoreFailedException;

/*
 * The test doubles are public API: products build their own tests on them.
 */

it('answers a queued host in order, then its fixed answer', function (): void {
    $resolver = (new FakeHostResolver)
        ->with('app.test', ['93.184.216.34'])
        ->queue('app.test', [['10.0.0.1'], ['10.0.0.2']]);

    expect($resolver->resolve('app.test'))->toBe(['10.0.0.1'])
        ->and($resolver->resolve('app.test'))->toBe(['10.0.0.2'])
        ->and($resolver->resolve('app.test'))->toBe(['93.184.216.34'])
        ->and($resolver->resolve('other.test'))->toBe([]);
});

it('builds a private faked factory that answers with the given responses', function (): void {
    $factory = FakeOutboundHttpFactory::fake(['https://hooks.test/*' => Http::response('faked', 202)]);

    $response = $factory->post('https://hooks.test/receive');

    expect($factory)->toBeInstanceOf(HttpFactory::class)
        ->and($response->status())->toBe(202)
        ->and($response->body())->toBe('faked')
        ->and(FakeOutboundHttpFactory::make())->not->toBe($factory);
});

it('fails the next claim only, once asked to', function (): void {
    $store = (new FakeSeenWebhookIdStore)->failNextWrite();

    try {
        $store->claim(WebhookIdClaim::generate('s', 'msg_1'), 60);
        $exception = null;
    } catch (WebhookIdStoreFailedException $caughtException) {
        $exception = $caughtException;
    }

    expect($exception?->getCode())->toBe(0)
        ->and($exception?->getPrevious()?->getMessage())->toBe('forced cache write failure')
        ->and($store->claim(WebhookIdClaim::generate('s', 'msg_1'), 60))->toBeTrue();
});

it('holds a claim for its ttl, at least one second, on the given clock', function (): void {
    $now = 1_700_000_000;
    $store = new FakeSeenWebhookIdStore(function () use (&$now): int {
        return $now;
    });

    expect($store->claim(WebhookIdClaim::generate('s', 'ttl'), 10))->toBeTrue()
        ->and($store->claim(WebhookIdClaim::generate('s', 'zero'), 0))->toBeTrue()
        ->and($store->claim(WebhookIdClaim::generate('s', 'zero'), 0))->toBeFalse();

    $now += 1;

    expect($store->claim(WebhookIdClaim::generate('s', 'zero'), 0))->toBeTrue()
        ->and($store->claim(WebhookIdClaim::generate('s', 'ttl'), 10))->toBeFalse();

    $now += 9;

    expect($store->claim(WebhookIdClaim::generate('s', 'ttl'), 10))->toBeTrue();
});

it('keeps a scope and an id apart from the same characters split differently', function (): void {
    $store = new FakeSeenWebhookIdStore(fn (): int => 1_700_000_000);

    expect($store->claim(WebhookIdClaim::generate('ab', 'c'), 60))->toBeTrue()
        ->and($store->claim(WebhookIdClaim::generate('a', 'bc'), 60))->toBeTrue()
        ->and($store->claim(WebhookIdClaim::generate('bc', 'a'), 60))->toBeTrue()
        ->and($store->claim(WebhookIdClaim::generate('c', 'ab'), 60))->toBeTrue();
});
