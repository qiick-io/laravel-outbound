<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Ssrf\UrlGuardOptions;
use Qiick\Outbound\Testing\FakeHostResolver;

/**
 * @param  (callable(): float)|null  $clock
 */
function pinnedClient(
    FakeHostResolver $resolver = new FakeHostResolver,
    bool $allowPrivate = false,
    mixed $clock = null,
): PinnedHttpClient {
    return new PinnedHttpClient(
        app(HttpFactory::class),
        new RemoteUrlGuard($resolver, allowPrivateHosts: $allowPrivate),
        $clock,
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
        ->and($seen['proxy'])->toBe('')
        ->and($seen['stream'])->toBeFalse()
        ->and($seen['verify'])->toBeTrue()
        ->and($seen['decode_content'])->toBeFalse()
        ->and($seen['headers']['Accept-Encoding'] ?? null)->toBe('identity')
        ->and($seen['curl'])->toHaveKey(CURLOPT_CONNECT_TO)
        ->and($seen['curl'][CURLOPT_CONNECT_TO])->toBe(['::93.184.216.34:443'])
        ->and($seen['curl'])->not->toHaveKey(CURLOPT_PROXY)
        ->and($seen['curl'])->not->toHaveKey(CURLOPT_NOPROXY);
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
        new HttpRequestOptions(followRedirects: true, maxRedirects: 3, allowCrossOriginRedirects: true),
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
        'http://example.test/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'http://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(
            followRedirects: true,
            maxRedirects: 2,
            allowCrossOriginRedirects: true,
            urlOptions: new UrlGuardOptions(httpsOnly: false),
        ),
    ))->toThrow(UnsafeUrl::class);
});

it('refuses cross-origin redirects unless explicitly opted in', function (): void {
    Http::fake([
        'https://app.test/start' => Http::response('', 307, ['Location' => 'https://evil.test/x']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: [
        'app.test' => ['93.184.216.34'],
        'evil.test' => ['93.184.216.35'],
    ]))->send(
        'GET',
        'https://app.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(OutboundHttpException::class, 'Cross-origin redirect is not allowed.');
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

it('does not follow 300/399; only 301/302/303/307/308', function (): void {
    Http::fake([
        'https://example.test/multi' => Http::response('multi', 300, ['Location' => 'https://example.test/ok']),
        'https://example.test/edge' => Http::response('edge', 399, ['Location' => 'https://example.test/ok']),
        'https://example.test/early' => Http::response('early-body', 299, ['Location' => 'https://example.test/ok']),
        'https://example.test/ok' => Http::response('should-not', 200),
    ]);

    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));
    $opts = new HttpRequestOptions(followRedirects: true, maxRedirects: 2);

    expect($client->send('GET', 'https://example.test/multi', pinnedSender(), $opts)->body())->toBe('multi')
        ->and($client->send('GET', 'https://example.test/edge', pinnedSender(), $opts)->body())->toBe('edge')
        ->and($client->send('GET', 'https://example.test/early', pinnedSender(), $opts)->body())->toBe('early-body');

    Http::assertSentCount(3);
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

it('ignores a 400 with Location and follows 308', function (): void {
    $methods = [];

    Http::fake([
        'https://example.test/bad' => Http::response('nope', 400, ['Location' => 'https://example.test/chosen']),
        'https://example.test/perm' => Http::response('', 308, ['Location' => 'https://example.test/chosen']),
        'https://example.test/chosen' => Http::response('picked', 200),
    ]);

    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));

    expect($client->send(
        'GET',
        'https://example.test/bad',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    )->body())->toBe('nope');

    $client->send(
        'POST',
        'https://example.test/perm',
        function (PendingRequest $request, string $url, string $method) use (&$methods) {
            $methods[] = $method;

            return match ($method) {
                'POST' => $request->post($url),
                default => $request->get($url),
            };
        },
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    );

    expect($methods)->toBe(['POST', 'POST']);
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

it('keeps HEAD after a 303 See Other', function (): void {
    $methods = [];

    Http::fake([
        'https://example.test/a' => Http::response('', 303, ['Location' => 'https://example.test/b']),
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

it('refuses https to http redirect downgrades', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => 'http://example.test/next']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(OutboundHttpException::class, 'HTTPS to HTTP redirect downgrade is not allowed.');
});

it('strips Authorization, Cookie and Proxy-Authorization on cross-origin hops', function (): void {
    $resolver = (new FakeHostResolver)
        ->queue('app.test', [['93.184.216.34']])
        ->queue('evil.test', [['93.184.216.35']]);

    Http::fake([
        'https://app.test/start' => Http::response('', 307, ['Location' => 'https://evil.test/steal']),
        'https://evil.test/steal' => Http::response('stolen', 200),
    ]);

    pinnedClient($resolver)->send(
        'POST',
        'https://app.test/start',
        function (PendingRequest $request, string $url, string $method) {
            return $request->withBody('secret-body', 'text/plain')->send($method, $url);
        },
        new HttpRequestOptions(
            followRedirects: true,
            maxRedirects: 2,
            allowCrossOriginRedirects: true,
            headers: [
                'Authorization' => 'Bearer leak-me',
                'Cookie' => 'sid=1',
                'Proxy-Authorization' => 'Basic abc',
                'X-Custom' => 'keep',
            ],
        ),
    );

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://app.test/start'
            && $request->hasHeader('Authorization')
            && $request->header('Authorization')[0] === 'Bearer leak-me';
    });

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://evil.test/steal'
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('Cookie')
            && ! $request->hasHeader('Proxy-Authorization')
            && $request->hasHeader('X-Custom');
    });
});

it('refuses a caller that changes the request URI after authorisation', function (): void {
    Http::fake(['https://evil.test/' => Http::response('nope', 200)]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: [
        'example.test' => ['93.184.216.34'],
        'evil.test' => ['93.184.216.35'],
    ]))->send(
        'GET',
        'https://example.test/',
        function (PendingRequest $request, string $url, string $method) {
            return $request->get('https://evil.test/');
        },
    ))->toThrow(OutboundHttpException::class, 'Request URI was altered after authorisation.');
});

it('rejects timeouts below 1s or above the configured caps', function (): void {
    Http::fake();

    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));

    expect(fn () => $client->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(timeoutSeconds: 0),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $client->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(timeoutSeconds: 120, maxTimeoutSeconds: 60),
    ))->toThrow(InvalidArgumentException::class);
});

it('chains a sanitised previous exception, not the raw transport message', function (): void {
    Http::fake(function () {
        throw new ConnectionException(
            'cURL error 7: Failed to connect to example.test port 443 after 0 ms: Could not connect to server (see https://curl.se/libcurl/c/libcurl-errors.html) for https://example.test/secret?token=abc'
        );
    });

    try {
        pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
            'GET',
            'https://example.test/secret?token=abc',
            pinnedSender(),
        );
        expect(false)->toBeTrue();
    } catch (OutboundHttpException $exception) {
        expect($exception->getMessage())->toBe('Outbound request failed: connection error.')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('Underlying transport error.')
            ->and($exception->getMessage())->not->toContain('token=abc')
            ->and($exception->getPrevious()?->getMessage())->not->toContain('example.test');
    }
});

it('re-enforces pin, proxy, verify and size guards in the innermost middleware', function (): void {
    $seenOptions = null;
    $seenRequest = null;
    $mock = new MockHandler([new Psr7Response(200, [], 'ok')]);

    $handler = function (RequestInterface $request, array $options) use ($mock, &$seenOptions, &$seenRequest) {
        $seenRequest = $request;
        $seenOptions = $options;

        return $mock($request, $options);
    };

    pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/hook',
        function (PendingRequest $request, string $url, string $method) use ($handler) {
            return $request
                ->setHandler($handler)
                ->withOptions([
                    'proxy' => 'http://127.0.0.1:9',
                    'allow_redirects' => true,
                    'stream' => true,
                    'verify' => false,
                    'decode_content' => true,
                    'curl' => [
                        CURLOPT_CONNECT_TO => ['::1.2.3.4:443'],
                        CURLOPT_PROXY => 'http://127.0.0.1:9',
                        CURLOPT_NOPROXY => '',
                    ],
                ])
                ->get($url);
        },
        new HttpRequestOptions(maxResponseBytes: 64),
    );

    expect($seenOptions)->not->toBeNull()
        ->and($seenOptions['proxy'])->toBe('')
        ->and($seenOptions['allow_redirects'])->toBeFalse()
        ->and($seenOptions['stream'])->toBeFalse()
        ->and($seenOptions['verify'])->toBeTrue()
        ->and($seenOptions['decode_content'])->toBeFalse()
        ->and($seenOptions['curl'][CURLOPT_CONNECT_TO])->toBe(['::93.184.216.34:443'])
        ->and($seenOptions['curl'])->not->toHaveKey(CURLOPT_PROXY)
        ->and($seenOptions['curl'])->not->toHaveKey(CURLOPT_NOPROXY)
        ->and($seenOptions)->toHaveKey('on_headers')
        ->and($seenOptions)->toHaveKey('progress')
        ->and($seenRequest?->getHeaderLine('Accept-Encoding'))->toBe('identity');

    $onHeaders = $seenOptions['on_headers'];
    $onHeaders(new Psr7Response(200, ['Content-Length' => '64'], '')); // equal to max: allowed
    $onHeaders(new Psr7Response(200, ['Content-Length' => 'nope'], '')); // non-digit: ignored
    expect(fn () => $onHeaders(new Psr7Response(200, ['Content-Length' => '65'], '')))
        ->toThrow(OutboundHttpException::class, 'Response exceeds max_response_bytes.');

    $progress = $seenOptions['progress'];
    $progress(64, 64); // equal to max: allowed
    expect(fn () => $progress(0, 65))->toThrow(OutboundHttpException::class, 'Response exceeds max_response_bytes.')
        ->and(fn () => $progress(65, 0))->toThrow(OutboundHttpException::class, 'Response exceeds max_response_bytes.');
});

it('treats default https/http ports as same-origin and strips case-insensitive auth headers', function (): void {
    Http::fake([
        'https://app.test/a' => Http::response('', 307, ['Location' => 'https://app.test:443/b']),
        'https://app.test/b' => Http::response('same', 200),
        'https://app.test/c' => Http::response('', 307, ['Location' => 'https://other.test/d']),
        'https://other.test/d' => Http::response('cross', 200),
    ]);

    $client = pinnedClient(new FakeHostResolver(answers: [
        'app.test' => ['93.184.216.34'],
        'other.test' => ['93.184.216.35'],
    ]));

    expect($client->send(
        'GET',
        'https://app.test/a',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    )->body())->toBe('same');

    $client->send(
        'GET',
        'https://app.test/c',
        pinnedSender(),
        new HttpRequestOptions(
            followRedirects: true,
            maxRedirects: 2,
            allowCrossOriginRedirects: true,
            headers: ['AUTHORIZATION' => 'Bearer x', 'X-Keep' => '1'],
        ),
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://other.test/d'
        && ! $request->hasHeader('AUTHORIZATION')
        && ! $request->hasHeader('Authorization')
        && $request->hasHeader('X-Keep'));
});

it('fails when the total deadline is already exhausted before a hop', function (): void {
    $ticks = [100.0, 100.5];

    expect(fn () => pinnedClient(
        new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]),
        clock: static function () use (&$ticks): float {
            return array_shift($ticks) ?? 100.5;
        },
    )->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(totalDeadlineSeconds: 1),
    ))->toThrow(OutboundHttpException::class, 'Outbound request total deadline exceeded.');
});

it('shortens per-hop timeouts to the remaining deadline budget', function (): void {
    Http::fake(['https://example.test/' => Http::response('ok', 200)]);

    $seen = null;
    $ticks = [10.0, 10.0];

    pinnedClient(
        new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]),
        clock: static function () use (&$ticks): float {
            return array_shift($ticks) ?? 10.0;
        },
    )->send(
        'GET',
        'https://example.test/',
        function (PendingRequest $request, string $url, string $method) use (&$seen) {
            $seen = $request->getOptions();

            return $request->get($url);
        },
        new HttpRequestOptions(timeoutSeconds: 5, connectTimeoutSeconds: 4, totalDeadlineSeconds: 3),
    );

    expect((float) $seen['timeout'])->toBe(3.0)
        ->and((float) $seen['connect_timeout'])->toBe(3.0);
});

it('unwraps a nested OutboundHttpException from the transport error chain', function (): void {
    Http::fake(function () {
        throw new ConnectionException(
            'wrapper with https://example.test/?token=secret',
            0,
            new OutboundHttpException('Response exceeds max_response_bytes.'),
        );
    });

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
    ))->toThrow(OutboundHttpException::class, 'Response exceeds max_response_bytes.');
});

it('does not unwrap a non-Outbound previous exception from the transport chain', function (): void {
    Http::fake(function () {
        throw new ConnectionException(
            'wrapper',
            0,
            new RuntimeException('not-outbound'),
        );
    });

    try {
        pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
            'GET',
            'https://example.test/',
            pinnedSender(),
        );
        expect(false)->toBeTrue();
    } catch (OutboundHttpException $exception) {
        expect($exception->getMessage())->toBe('Outbound request failed: connection error.')
            ->and($exception->getPrevious()?->getMessage())->toBe('Underlying transport error.');
    }
});

it('rejects connect timeouts above the configured cap and total deadlines below 1s', function (): void {
    $client = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]));

    expect(fn () => $client->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(connectTimeoutSeconds: 40, maxConnectTimeoutSeconds: 30),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $client->send(
        'GET',
        'https://example.test/',
        pinnedSender(),
        new HttpRequestOptions(totalDeadlineSeconds: 0),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects a Location that is only whitespace after trim', function (): void {
    Http::fake([
        'https://example.test/start' => Http::response('', 302, ['Location' => '   ']),
    ]);

    expect(fn () => pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'https://example.test/start',
        pinnedSender(),
        new HttpRequestOptions(followRedirects: true, maxRedirects: 2),
    ))->toThrow(OutboundHttpException::class, 'Redirect response is missing a Location header.');
});

it('keeps same-origin http default port 80 redirects without the cross-origin opt-in', function (): void {
    Http::fake([
        'http://example.test/a' => Http::response('', 302, ['Location' => 'http://example.test:80/b']),
        'http://example.test/b' => Http::response('port80', 200),
    ]);

    $response = pinnedClient(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]))->send(
        'GET',
        'http://example.test/a',
        pinnedSender(),
        new HttpRequestOptions(
            followRedirects: true,
            maxRedirects: 2,
            urlOptions: new UrlGuardOptions(httpsOnly: false),
        ),
    );

    expect($response->body())->toBe('port80');
});
