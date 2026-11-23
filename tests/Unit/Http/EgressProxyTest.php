<?php

declare(strict_types=1);

use Qiick\Outbound\Http\EgressProxy;

/*
 * Standards review R3: the egress proxy is deployment config. Its URL is
 * checked once, at boot, and its credentials never leave the object as text.
 */

it('accepts an http or https proxy with an optional port and credentials', function (string $url): void {
    expect(EgressProxy::fromUrl($url)->url())->toBe($url);
})->with([
    'http://proxy.internal:3128',
    'https://proxy.example.com',
    'http://10.0.0.5:8080',
    'http://user:s3cret@proxy.internal:3128',
    'http://[fd00::5]:3128',
]);

it('refuses anything else, without echoing the value', function (string $url): void {
    try {
        EgressProxy::fromUrl($url);
        $message = null;
    } catch (InvalidArgumentException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBe('outbound.http.egress_proxy must be an http:// or https:// proxy URL with a host and nothing after the port.')
        ->and($message)->not->toContain('s3cret');
})->with([
    'socks, which could resolve names itself' => ['socks5h://user:s3cret@proxy:1080'],
    'no scheme' => ['proxy.internal:3128'],
    'no host' => ['http://:3128'],
    'a path' => ['http://user:s3cret@proxy.internal:3128/path'],
    'a query' => ['http://proxy.internal:3128?x=1'],
    'a fragment' => ['http://proxy.internal:3128#x'],
    'just a slash' => ['http://proxy.internal:3128/'],
    'not a URL' => ['not a url'],
    'empty' => [''],
]);

it('never shows its credentials as text, in a dump or serialized', function (): void {
    $proxy = EgressProxy::fromUrl('http://user:s3cret@proxy.internal:3128');

    expect((string) $proxy)->toBe('http://[REDACTED]@proxy.internal:3128')
        ->and(print_r($proxy, true))->not->toContain('s3cret')
        ->and(fn () => serialize($proxy))->toThrow(LogicException::class, 'An egress proxy must not be serialized.');
});

it('shows a proxy without credentials as it is', function (): void {
    expect((string) EgressProxy::fromUrl('https://proxy.example.com'))->toBe('https://proxy.example.com');
});
