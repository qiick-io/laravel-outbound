<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Ssrf\UrlGuardOptions;
use Qiick\Outbound\Testing\FakeHostResolver;

/**
 * @param  callable(PendingRequest, string, string): Response  $unused
 */
function pinnedClient(FakeHostResolver $resolver = new FakeHostResolver, bool $allowPrivate = false): PinnedHttpClient
{
    return new PinnedHttpClient(
        app(HttpFactory::class),
        new RemoteUrlGuard($resolver, allowPrivateHosts: $allowPrivate),
    );
}

/**
 * @return callable(PendingRequest, string, string): Response
 */
function pinnedSender(): callable
{
    return function (PendingRequest $request, string $url, string $method) {
        return match (strtoupper($method)) {
            'POST' => $request->post($url),
            'PUT' => $request->put($url),
            'PATCH' => $request->patch($url),
            'DELETE' => $request->delete($url),
            'HEAD' => $request->head($url),
            default => $request->get($url),
        };
    };
}

it('pins CONNECT_TO, disables redirects and proxies, and returns the response', function (): void {
    Http::fake([
        'https://example.test/hook' => Http::response('ok', 200),
    ]);

    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));
    $seen = null;

    $response = $client->send(
        'GET',
        'https://example.test/hook',
        function (PendingRequest $request, string $url, string $method) use (&$seen) {
            $seen = $request->getOptions();

            return $request->get($url);
        },
    );

    expect($response->body())->toBe('ok')
        ->and($seen['allow_redirects'])->toBeFalse()
        ->and($seen['proxy'])->toMatchArray(['http' => null, 'https' => null, 'no' => ['*']])
        ->and($seen['curl'])->toHaveKey(CURLOPT_CONNECT_TO)
        ->and($seen['curl'][CURLOPT_CONNECT_TO])->toBe(['::93.184.216.34:443'])
        ->and($seen['curl'][CURLOPT_PROXY])->toBe('')
        ->and($seen['curl'][CURLOPT_NOPROXY])->toBe('*');
});

it('refuses an unsafe URL before any HTTP call', function (): void {
    Http::fake();

    expect(fn () => pinnedClient()->send('GET', 'https://127.0.0.1/', pinnedSender()))
        ->toThrow(UnsafeUrl::class);

    Http::assertNothingSent();
});

it('applies timeouts from HttpRequestOptions', function (): void {
    Http::fake(['https://example.test/' => Http::response('{}', 200)]);

    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));
    $seen = null;

    $client->send(
        'GET',
        'https://example.test/',
        function (PendingRequest $request, string $url, string $method) use (&$seen) {
            $seen = $request->getOptions();

            return $request->get($url);
        },
        new HttpRequestOptions(timeoutSeconds: 9, connectTimeoutSeconds: 2),
    );

    expect((float) $seen['timeout'])->toBe(9.0)
        ->and((float) $seen['connect_timeout'])->toBe(2.0);
});

it('rejects a response larger than max_response_bytes', function (): void {
    Http::fake(['https://example.test/' => Http::response(str_repeat('a', 100), 200)]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(maxResponseBytes: 50),
    ))->toThrow(OutboundHttpException::class, 'Response exceeds max_response_bytes.');
});

it('does not follow redirects when followRedirects is off', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => 'https://example.test/next']),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
    );

    expect($response->status())->toBe(302);
    Http::assertSentCount(1);
});

it('re-authorises and re-pins each redirect hop when followRedirects is on', function (): void {
    $resolver = (new FakeHostResolver)
        ->queue('start.test', [['93.184.216.34']])
        ->queue('next.test', [['93.184.216.35']]);

    Http::fake([
        'https://start.test/a' => Http::response('', 302, ['Location' => 'https://next.test/b']),
        'https://next.test/b' => Http::response('done', 200),
    ]);

    $pins = [];

    $response = pinnedClient($resolver)->send(
        'GET',
        'https://start.test/a',
        function (PendingRequest $request, string $url, string $method) use (&$pins) {
            $pins[] = $request->getOptions()['curl'][CURLOPT_CONNECT_TO][0] ?? null;

            return $request->get($url);
        },
        new HttpRequestOptions(followRedirects: true, maxRedirects: 3),
    );

    expect($response->body())->toBe('done')
        ->and($pins)->toBe(['::93.184.216.34:443', '::93.184.216.35:443']);
});

it('fails closed when redirect hops exceed maxRedirects', function (): void {
    Http::fake([
        'https://example.test/*' => Http::response('', 302, ['Location' => 'https://example.test/again']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 1),
    ))->toThrow(OutboundHttpException::class, 'Redirect hop limit exceeded.');

    // hops starts at 0: two requests then throw. hops=-1 would allow a third.
    Http::assertSentCount(2);
});

it('rejects a file: redirect Location', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => 'file:///etc/passwd']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(OutboundHttpException::class);
});

it('rejects a redirect Location with userinfo', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => 'https://user:pass@evil.test/']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(OutboundHttpException::class);
});

it('rejects a metadata redirect Location via the SSRF guard', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(UnsafeUrl::class);
});

it('resolves a relative Location against the prior URL before re-auth', function (): void {
    Http::fake([
        'https://example.test/dir/a' => Http::response('', 302, ['Location' => '../b']),
        'https://example.test/b' => Http::response('rel', 200),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/dir/a',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    );

    expect($response->body())->toBe('rel');
});

it('honours per-call UrlGuardOptions httpsOnly on the pinned client', function (): void {
    Http::fake();

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'http://example.test/',
        pinnedSender(),
        new HttpRequestOptions(urlOptions: new UrlGuardOptions(httpsOnly: true)),
    ))->toThrow(UnsafeUrl::class);
});

it('turns POST into GET after a 303 See Other redirect', function (): void {
    $methods = [];

    Http::fake([
        'https://example.test/post' => Http::response('', 303, ['Location' => 'https://example.test/get']),
        'https://example.test/get' => Http::response('see-other', 200),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'POST',
        'https://example.test/post',
        function (PendingRequest $request, string $url, string $method) use (&$methods) {
            $methods[] = $method;

            return match ($method) {
                'POST' => $request->post($url),
                default => $request->get($url),
            };
        },
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    );

    expect($response->body())->toBe('see-other')
        ->and($methods)->toBe(['POST', 'GET']);
});

it('rejects a redirect with an empty Location when following', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => '']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(OutboundHttpException::class, 'Redirect response is missing a Location header.');
});

it('trims whitespace around a Location header before resolving', function (): void {
    Http::fake([
        'https://example.test/a' => Http::response('', 302, ['Location' => ' https://example.test/b ']),
        'https://example.test/b' => Http::response('trimmed', 200),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/a',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    );

    expect($response->body())->toBe('trimmed');
});

it('lowercases the Location scheme before the allow-list check', function (): void {
    Http::fake([
        'https://example.test/a' => Http::response('', 302, ['Location' => 'HTTPS://example.test/b']),
        'https://example.test/b' => Http::response('scheme', 200),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/a',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    );

    expect($response->body())->toBe('scheme');
});

it('rejects a Location that only has a userinfo user component', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => 'https://user@evil.test/']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(OutboundHttpException::class, 'Redirect Location must not contain credentials.');
});

it('keeps HEAD after a 302 (kills BooleanAndToBooleanOr on safe-method check)', function (): void {
    $methods = [];

    Http::fake([
        'https://example.test/a' => Http::response('', 302, ['Location' => 'https://example.test/b']),
        'https://example.test/b' => Http::response('', 200),
    ]);

    pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'HEAD',
        'https://example.test/a',
        function (PendingRequest $request, string $url, string $method) use (&$methods) {
            $methods[] = $method;

            return $request->head($url);
        },
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    );

    expect($methods)->toBe(['HEAD', 'HEAD']);
});

it('follows a 399 status and does not treat 299 as a redirect', function (): void {
    Http::fake([
        'https://example.test/edge' => Http::response('', 399, ['Location' => 'https://example.test/ok']),
        'https://example.test/ok' => Http::response('edge', 200),
        'https://example.test/early' => Http::response('early-body', 299, ['Location' => 'https://example.test/ok']),
    ]);

    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));

    expect($client->send(
        'GET',
        'https://example.test/edge',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    )->body())->toBe('edge');

    expect($client->send(
        'GET',
        'https://example.test/early',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    )->body())->toBe('early-body');
});

it('allows exactly maxRedirects hops (kills > vs >= on the hop ceiling)', function (): void {
    Http::fake([
        'https://example.test/a' => Http::response('', 302, ['Location' => 'https://example.test/b']),
        'https://example.test/b' => Http::response('one-hop', 200),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/a',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 1),
    );

    expect($response->body())->toBe('one-hop');
    Http::assertSentCount(2);
});

it('does not follow when followRedirects is true but maxRedirects is 0', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => 'https://example.test/next']),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 0),
    );

    expect($response->status())->toBe(302);
    Http::assertSentCount(1);
});

it('follows a 300 Multiple Choices Location and ignores a 400 with Location', function (): void {
    Http::fake([
        'https://example.test/multi' => Http::response('', 300, ['Location' => 'https://example.test/chosen']),
        'https://example.test/chosen' => Http::response('picked', 200),
        'https://example.test/bad' => Http::response('nope', 400, ['Location' => 'https://example.test/chosen']),
    ]);

    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));

    expect($client->send(
        'GET',
        'https://example.test/multi',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    )->body())->toBe('picked');

    expect($client->send(
        'GET',
        'https://example.test/bad',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    )->body())->toBe('nope');
});

it('uppercases the request method before the first hop', function (): void {
    $methods = [];

    Http::fake(['https://example.test/' => Http::response('ok', 200)]);

    pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'get',
        'https://example.test/',
        function (PendingRequest $request, string $url, string $method) use (&$methods) {
            $methods[] = $method;

            return $request->get($url);
        },
    );

    expect($methods)->toBe(['GET']);
});

it('converts POST to GET after 301 and 302, but keeps POST after 307', function (int $status, array $expectedMethods): void {
    $methods = [];

    Http::fake([
        'https://example.test/post' => Http::response('', $status, ['Location' => 'https://example.test/next']),
        'https://example.test/next' => Http::response('ok', 200),
    ]);

    pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'POST',
        'https://example.test/post',
        function (PendingRequest $request, string $url, string $method) use (&$methods) {
            $methods[] = $method;

            return match ($method) {
                'POST' => $request->post($url),
                default => $request->get($url),
            };
        },
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    );

    expect($methods)->toBe($expectedMethods);
})->with([
    '301' => [301, ['POST', 'GET']],
    '302' => [302, ['POST', 'GET']],
    '307' => [307, ['POST', 'POST']],
]);

it('allows a response body exactly at max_response_bytes', function (): void {
    Http::fake(['https://example.test/' => Http::response(str_repeat('a', 50), 200)]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(maxResponseBytes: 50),
    );

    expect(strlen($response->body()))->toBe(50);
});

it('skips the size check when maxResponseBytes is null', function (): void {
    Http::fake(['https://example.test/' => Http::response(str_repeat('a', 200), 200)]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(maxResponseBytes: null),
    );

    expect(strlen($response->body()))->toBe(200);
});
