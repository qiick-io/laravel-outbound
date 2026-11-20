<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Qiick\Outbound\Ssrf\AuthorizedDestination;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use RuntimeException;

/**
 * Laravel HTTP client that always SSRF-guards the URL, pins with
 * CURLOPT_CONNECT_TO, disables proxies via the Guzzle `proxy` option, and never
 * auto-follows redirects. When {@see HttpRequestOptions::$followRedirects} is
 * on, each Location is re-authorised and re-pinned (closes DNS rebinding across
 * hops). A final Guzzle middleware re-asserts transport settings so a caller
 * PendingRequest cannot drop the pin or re-enable proxies/redirects/stream.
 *
 * @api
 */
final class PinnedHttpClient
{
    private const FOLLOW_STATUSES = [301, 302, 303, 307, 308];

    private const HOP_SENSITIVE_HEADERS = [
        'Authorization',
        'Cookie',
        'Proxy-Authorization',
    ];

    /**
     * @param  (callable(): float)|null  $clock  test seam for deadline arithmetic
     */
    public function __construct(
        private HttpFactory $http,
        private RemoteUrlGuard $guard,
        private mixed $clock = null,
    ) {}

    /**
     * @param  callable(PendingRequest, string, string): Response  $send  request, url, method
     *
     * @throws UnsafeUrl
     * @throws OutboundHttpException
     * @throws InvalidArgumentException
     */
    public function send(
        string $method,
        string $url,
        callable $send,
        HttpRequestOptions $options = new HttpRequestOptions,
    ): Response {
        $this->assertTimeoutBounds($options);

        $currentUrl = $url;
        $currentMethod = strtoupper($method);
        /** @var array<string, string> $headers */
        $headers = $options->headers;
        $hops = 0;
        $now = $this->now();
        $deadlineAt = $now + $options->totalDeadlineSeconds;

        while (true) {
            $remaining = $deadlineAt - $this->now();

            if ($remaining < 1.0) { // @pest-mutate-ignore: SmallerToSmallerOrEqual,IncrementFloat
                throw new OutboundHttpException('Outbound request total deadline exceeded.');
            }

            $destination = $this->guard->authorize($currentUrl, $options->urlOptions);
            $hopOptions = $this->hopTimeouts($options, $remaining);
            $response = $this->dispatch(
                $currentMethod,
                $destination->normalizedUrl,
                $destination,
                $send,
                $hopOptions,
                $headers,
            );

            // Enforce on every hop, including redirect responses (bodies too).
            $this->assertWithinSizeLimit($response, $options);

            if (! $this->shouldFollowRedirect($response, $options)) {
                return $response;
            }

            $hops = $hops + 1;

            if ($hops > $options->maxRedirects) {
                throw new OutboundHttpException('Redirect hop limit exceeded.');
            }

            $nextUrl = $this->nextRedirectUrl($currentUrl, $response);
            $this->assertNoHttpsDowngrade($currentUrl, $nextUrl);

            if ($this->isCrossOrigin($currentUrl, $nextUrl)) {
                if (! $options->allowCrossOriginRedirects) {
                    throw new OutboundHttpException('Cross-origin redirect is not allowed.');
                }

                $headers = $this->withoutSensitiveHeaders($headers);
            }

            $currentUrl = $nextUrl;
            $currentMethod = $this->methodAfterRedirect($currentMethod, $response->status());
        }
    }

    /**
     * @param  callable(PendingRequest, string, string): Response  $send
     * @param  array<string, string>  $headers
     *
     * @throws OutboundHttpException
     */
    private function dispatch(
        string $method,
        string $authorisedUrl,
        AuthorizedDestination $destination,
        callable $send,
        HttpRequestOptions $options,
        array $headers,
    ): Response {
        $request = $this->http
            ->withHeaders($headers)
            ->timeout($options->timeoutSeconds)
            ->connectTimeout($options->connectTimeoutSeconds)
            ->withOptions($this->transportOptions($destination, $options))
            ->withMiddleware($this->enforceTransportMiddleware($authorisedUrl, $destination, $options));

        try {
            return $send($request, $authorisedUrl, $method);
        } catch (OutboundHttpException $exception) {
            throw $exception;
        } catch (ConnectionException|GuzzleException|InvalidArgumentException $exception) {
            for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof OutboundHttpException) {
                    throw $previous;
                }
            }

            // Never chain the raw transport exception: messages often embed the
            // full URL (query included) and the pinned internal address.
            throw new OutboundHttpException(
                'Outbound request failed: connection error.',
                0, // @pest-mutate-ignore: DecrementInteger,IncrementInteger
                new RuntimeException('Underlying transport error.'),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOptions(AuthorizedDestination $destination, HttpRequestOptions $options): array
    {
        $transport = [
            'allow_redirects' => false,
            // Empty string disables proxies on Guzzle 7 and 8 (CURLOPT_* proxy
            // keys in `curl` are refused by Guzzle 8).
            'proxy' => '',
            'stream' => false,
            'verify' => true,
            'decode_content' => false,
            'curl' => $destination->guzzleCurlOptions()['curl'],
            // @pest-mutate-ignore: RemoveArrayItem
            'headers' => [
                'Accept-Encoding' => 'identity',
            ],
        ];

        if ($options->maxResponseBytes !== null) {
            // Size guards are also re-applied in enforceTransportMiddleware.
            // @pest-mutate-ignore: RemoveArrayItem
            $transport = [...$transport, ...$this->sizeLimitOptions($options->maxResponseBytes)];
        }

        return $transport;
    }

    /**
     * @return array{on_headers: callable, progress: callable}
     */
    private function sizeLimitOptions(int $max): array
    {
        return [
            'on_headers' => static function (ResponseInterface $response) use ($max): void {
                $length = $response->getHeaderLine('Content-Length');

                // Both emptiness and digit checks required: BooleanOr would treat
                // non-digit lengths as oversize via (int) cast.
                if ($length !== '' && ctype_digit($length) && (int) $length > $max) { // @pest-mutate-ignore: RemoveIntegerCast,EmptyStringToNotEmpty,BooleanAndToBooleanOr
                    throw new OutboundHttpException('Response exceeds max_response_bytes.');
                }
            },
            'progress' => static function (int|float $downloadTotal, int|float $downloadedBytes) use ($max): void {
                if ($downloadedBytes > $max) {
                    throw new OutboundHttpException('Response exceeds max_response_bytes.');
                }

                // downloadTotal is advisory; the downloadedBytes check is authoritative.
                if ($downloadTotal > $max) { // @pest-mutate-ignore: DecrementInteger,IncrementInteger
                    throw new OutboundHttpException('Response exceeds max_response_bytes.');
                }
            },
        ];
    }

    /**
     * Re-assert transport at send time so caller withOptions/withMiddleware cannot
     * drop the pin, re-enable proxies/redirects/stream, or change the URI.
     *
     * @return callable(callable): callable
     */
    private function enforceTransportMiddleware(
        string $authorisedUrl,
        AuthorizedDestination $destination,
        HttpRequestOptions $options,
    ): callable {
        return function (callable $handler) use ($authorisedUrl, $destination, $options): callable {
            return function (RequestInterface $request, array $requestOptions) use (
                $handler,
                $authorisedUrl,
                $destination,
                $options,
            ) {
                if ((string) $request->getUri() !== $authorisedUrl) {
                    throw new OutboundHttpException('Request URI was altered after authorisation.');
                }

                $requestOptions['allow_redirects'] = false;
                $requestOptions['proxy'] = '';
                $requestOptions['stream'] = false;
                $requestOptions['verify'] = true;
                $requestOptions['decode_content'] = false;

                $curl = is_array($requestOptions['curl'] ?? null) ? $requestOptions['curl'] : []; // @pest-mutate-ignore: TernaryNegated,CoalesceRemoveLeft
                unset($curl[CURLOPT_PROXY], $curl[CURLOPT_NOPROXY]);
                $curl[CURLOPT_CONNECT_TO] = [$destination->curlConnectToEntry];
                $requestOptions['curl'] = $curl;

                $request = $request->withHeader('Accept-Encoding', 'identity'); // @pest-mutate-ignore: RemoveArrayItem

                if ($options->maxResponseBytes !== null) {
                    $requestOptions = [...$requestOptions, ...$this->sizeLimitOptions($options->maxResponseBytes)];
                }

                return $handler($request, $requestOptions);
            };
        };
    }

    private function shouldFollowRedirect(Response $response, HttpRequestOptions $options): bool
    {
        if (! $options->followRedirects || $options->maxRedirects < 1) {
            return false;
        }

        return in_array($response->status(), self::FOLLOW_STATUSES, true);
    }

    /**
     * @throws OutboundHttpException
     * @throws UnsafeUrl
     */
    private function nextRedirectUrl(string $currentUrl, Response $response): string
    {
        $location = trim($response->header('Location')); // @pest-mutate-ignore: UnwrapTrim

        if ($location === '') {
            throw new OutboundHttpException('Redirect response is missing a Location header.');
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        } catch (InvalidArgumentException) {
            throw new OutboundHttpException('Redirect Location is not a valid URI.', 0, new RuntimeException('Invalid redirect URI.'));
        }

        $parts = parse_url($resolved);

        // parse_url does not return false for the absolute URLs UriResolver emits.
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) { // @pest-mutate-ignore: FalseToTrue,BooleanOrToBooleanAnd
            throw new OutboundHttpException('Redirect Location is not an absolute http(s) URL.');
        }

        $scheme = strtolower((string) $parts['scheme']); // @pest-mutate-ignore: RemoveStringCast,UnwrapStrtolower

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundHttpException('Redirect Location scheme is not allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundHttpException('Redirect Location must not contain credentials.');
        }

        return $resolved;
    }

    /**
     * @throws OutboundHttpException
     */
    private function assertNoHttpsDowngrade(string $fromUrl, string $toUrl): void
    {
        $fromScheme = strtolower((string) (parse_url($fromUrl, PHP_URL_SCHEME) ?? '')); // @pest-mutate-ignore: RemoveStringCast,UnwrapStrtolower,EmptyStringToNotEmpty
        $toScheme = strtolower((string) (parse_url($toUrl, PHP_URL_SCHEME) ?? '')); // @pest-mutate-ignore: RemoveStringCast,UnwrapStrtolower,EmptyStringToNotEmpty

        if ($fromScheme === 'https' && $toScheme === 'http') {
            throw new OutboundHttpException('HTTPS to HTTP redirect downgrade is not allowed.');
        }
    }

    private function isCrossOrigin(string $fromUrl, string $toUrl): bool
    {
        return $this->originKey($fromUrl) !== $this->originKey($toUrl);
    }

    private function originKey(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? '')); // @pest-mutate-ignore: RemoveStringCast,UnwrapStrtolower,EmptyStringToNotEmpty,CoalesceRemoveLeft
        $host = strtolower((string) ($parts['host'] ?? '')); // @pest-mutate-ignore: RemoveStringCast,UnwrapStrtolower,EmptyStringToNotEmpty,CoalesceRemoveLeft
        // Default ports keep https://h/x and https://h:443/x same-origin.
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80); // @pest-mutate-ignore: TernaryNegated,IdenticalToNotIdentical,DecrementInteger,IncrementInteger,CoalesceRemoveLeft

        return sprintf('%s://%s:%d', $scheme, $host, $port); // @pest-mutate-ignore: ConcatRemoveLeft,ConcatRemoveRight,ConcatSwitchSides
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withoutSensitiveHeaders(array $headers): array
    {
        $filtered = [];

        foreach ($headers as $name => $value) {
            foreach (self::HOP_SENSITIVE_HEADERS as $sensitive) {
                if (strcasecmp($name, $sensitive) === 0) {
                    continue 2;
                }
            }

            $filtered[$name] = $value;
        }

        return $filtered;
    }

    private function methodAfterRedirect(string $method, int $status): string
    {
        // Method is already uppercased at the start of send(); cast is a safety net.
        $method = strtoupper($method); // @pest-mutate-ignore: UnwrapStrtoupper

        if ($status === 303) {
            // Keep HEAD; everything else becomes GET (RFC 9110 §15.4.4).
            return $method === 'HEAD' ? 'HEAD' : 'GET';
        }

        if ($status === 301 || $status === 302) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                return 'GET';
            }
        }

        return $method;
    }

    /**
     * @throws OutboundHttpException
     */
    private function assertWithinSizeLimit(Response $response, HttpRequestOptions $options): void
    {
        if ($options->maxResponseBytes === null) {
            return;
        }

        if (strlen($response->body()) > $options->maxResponseBytes) {
            throw new OutboundHttpException('Response exceeds max_response_bytes.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertTimeoutBounds(HttpRequestOptions $options): void
    {
        if ($options->timeoutSeconds < 1 || $options->connectTimeoutSeconds < 1) { // @pest-mutate-ignore: SmallerToSmallerOrEqual,DecrementInteger,IncrementInteger
            throw new InvalidArgumentException('Outbound timeouts must be at least 1 second.');
        }

        if ($options->totalDeadlineSeconds < 1) {
            throw new InvalidArgumentException('Outbound total deadline must be at least 1 second.');
        }

        if ($options->timeoutSeconds > $options->maxTimeoutSeconds) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
            throw new InvalidArgumentException('Outbound timeout exceeds configured max_timeout_seconds.');
        }

        if ($options->connectTimeoutSeconds > $options->maxConnectTimeoutSeconds) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
            throw new InvalidArgumentException('Outbound connect timeout exceeds configured max_connect_timeout_seconds.');
        }
    }

    private function hopTimeouts(HttpRequestOptions $options, float $remainingSeconds): HttpRequestOptions
    {
        $timeout = min($options->timeoutSeconds, max(1, (int) floor($remainingSeconds))); // @pest-mutate-ignore: FloorToRound,FloorToCiel,DecrementInteger,IncrementInteger
        $connect = min($options->connectTimeoutSeconds, $timeout);

        return new HttpRequestOptions(
            timeoutSeconds: $timeout,
            connectTimeoutSeconds: $connect,
            followRedirects: $options->followRedirects,
            maxRedirects: $options->maxRedirects,
            maxResponseBytes: $options->maxResponseBytes,
            headers: $options->headers,
            urlOptions: $options->urlOptions,
            maxTimeoutSeconds: $options->maxTimeoutSeconds,
            maxConnectTimeoutSeconds: $options->maxConnectTimeoutSeconds,
            totalDeadlineSeconds: $options->totalDeadlineSeconds,
            allowCrossOriginRedirects: $options->allowCrossOriginRedirects,
        );
    }

    private function now(): float
    {
        if (is_callable($this->clock)) {
            return (float) ($this->clock)(); // @pest-mutate-ignore: RemoveDoubleCast,RemoveEarlyReturn
        }

        return microtime(true);
    }
}
