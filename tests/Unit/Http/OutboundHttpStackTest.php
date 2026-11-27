<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\OutboundHttpStackData;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Testing\FakeOutboundHttpFactory;

it('gives the pinned client the same isolated guard and limits as the caller', function (): void {
    $resolver = new FakeHostResolver(answers: [
        'idp.example.test' => ['10.0.0.12'],
        'public.example.test' => ['93.184.216.34'],
    ]);
    $factory = FakeOutboundHttpFactory::fake([
        'http://idp.example.test/discovery' => HttpFactory::response(['issuer' => 'fixture']),
    ]);
    $limits = new OutboundHttpLimits(maxTimeoutSeconds: 2);
    $privateStack = OutboundHttpStackData::make(
        $resolver, allowPrivateHosts: true, httpsOnly: false, limits: $limits, httpFactory: $factory,
    );
    $publicStack = OutboundHttpStackData::make($resolver);
    $url = 'http://idp.example.test/discovery';
    $configure = static function (): void {};

    expect($privateStack->guard->authorize($url)->pinnedAddress)->toBe('10.0.0.12')
        ->and($privateStack->client->send('GET', $url, $configure, new HttpRequestOptions(timeoutSeconds: 2))->json('issuer'))
        ->toBe('fixture')
        ->and(fn () => $publicStack->guard->authorize('https://idp.example.test/discovery'))->toThrow(UnsafeUrl::class)
        ->and(fn () => $publicStack->guard->authorize('http://public.example.test/discovery'))->toThrow(UnsafeUrl::class)
        ->and(fn () => $publicStack->client->send('GET', $url, $configure))->toThrow(UnsafeUrl::class)
        ->and(fn () => $privateStack->client->send('GET', $url, $configure, new HttpRequestOptions(timeoutSeconds: 3)))
        ->toThrow(InvalidArgumentException::class, 'max_timeout_seconds');
});
