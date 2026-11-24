<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Testing\FakeOutboundHttpFactory;

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
