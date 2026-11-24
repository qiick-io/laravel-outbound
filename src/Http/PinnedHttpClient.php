<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Qiick\Outbound\Enums\OutboundTransportErrorKindEnum;
use Qiick\Outbound\Ssrf\AuthorizedDestination;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use ReflectionObject;
use RuntimeException;

/**
 * Laravel HTTP client that SSRF-guards the URL, pins with CURLOPT_CONNECT_TO,
 * disables proxies, and never auto-follows redirects. Callers configure each
 * request through {@see OutboundRequest} only — they never receive a
 * PendingRequest. The client uses the injected HttpFactory only (apps should
 * bind a private Factory, not the shared Http facade factory). Transport
 * settings are re-applied by wrapping the curl handler; any caller/global
 * `handler` option is stripped.
 *
 * @api
 */
final class PinnedHttpClient
{
    private const FOLLOW_STATUSES = [301, 302, 303, 307, 308];

    /** Headers forwarded on an opted-in cross-origin hop (body usually dropped). */
    private const CROSS_ORIGIN_SAFE_HEADERS = [
        'Accept',
        'Accept-Language',
        'User-Agent',
        'Content-Type',
    ];

    private const BODY_HEADERS = [
        'Content-Type',
        'Content-Length',
    ];

    /**
     * @param  (callable(): float)|null  $clock  test seam for deadline arithmetic
     * @param  callable|null  $transferHandler  test seam replacing CurlHandler
     * @param  EgressProxy|null  $egressProxy  `outbound.http.egress_proxy`; null
     *                                         sends directly with every proxy off
     */
    public function __construct(
        private HttpFactory $http,
        private RemoteUrlGuard $guard,
        private OutboundHttpLimits $limits = new OutboundHttpLimits,
        private mixed $clock = null,
        private mixed $transferHandler = null,
        private ?EgressProxy $egressProxy = null,
    ) {}

    /**
     * @param  callable(OutboundRequest): void  $configure
     *
     * @throws UnsafeUrl
     * @throws OutboundHttpException
     * @throws InvalidArgumentException
     */
    public function send(
        string $method,
        string $url,
        callable $configure,
        HttpRequestOptions $options = new HttpRequestOptions,
    ): Response {
        $this->assertTimeoutBounds($options);

        $builder = new OutboundRequest;
        $builder->withHeaders($options->headers);
        $configure($builder);
        $snapshot = $builder->snapshot();

        $currentUrl = $url;
        $currentMethod = strtoupper($method); // @pest-mutate-ignore: UnwrapStrtoupper
        $includeBody = $snapshot['body'] !== null;
        $hops = 0;
        $deadlineAt = $this->now() + $this->limits->totalDeadlineSeconds;

        while (true) {
            $remaining = $deadlineAt - $this->now();

            if ($remaining < 1.0) {
                throw OutboundHttpException::forTransport(OutboundTransportErrorKindEnum::Timeout, 'Outbound request total deadline exceeded.');
            }

            $destination = $this->guard->authorize($currentUrl, $options->urlOptions);
            $hopTimeout = min($options->timeoutSeconds, max(1, (int) floor($remaining)));
            $hopConnect = min($options->connectTimeoutSeconds, $hopTimeout);

            $response = $this->dispatch(
                $currentMethod,
                $destination,
                $snapshot,
                $includeBody,
                $hopTimeout,
                $hopConnect,
                $options,
            );
            // Caller query values apply to the first hop only — never rewrite
            // subsequent redirect Locations / authorised URLs.
            $snapshot['query'] = [];

            $this->assertWithinSizeLimit($response, $options);

            if (! $this->shouldFollowRedirect($response, $options)) {
                return $response;
            }

            $hops = $hops + 1;

            if ($hops > $options->maxRedirects) {
                throw new OutboundHttpException('Redirect hop limit exceeded.');
            }

            $status = $response->status();
            $nextUrl = $this->nextRedirectUrl($currentUrl, $response);
            $this->assertNoHttpsDowngrade($currentUrl, $nextUrl);

            $crossOrigin = $this->isCrossOrigin($currentUrl, $nextUrl);

            if ($crossOrigin) {
                if (! $options->allowCrossOriginRedirects) {
                    throw new OutboundHttpException('Cross-origin redirect is not allowed.');
                }

                if ($status === 307 || $status === 308) {
                    throw new OutboundHttpException('Cross-origin 307/308 redirect is not allowed.');
                }

                $snapshot['headers'] = $this->onlyHeaders($snapshot['headers'], self::CROSS_ORIGIN_SAFE_HEADERS);
                // Cross-origin only allows 301/302/303, which become GET/HEAD below
                // and drop the body there — Content-Type is then stripped with BODY_HEADERS.
            }

            $nextMethod = $this->methodAfterRedirect($currentMethod, $status);

            if ($nextMethod === 'GET' || $nextMethod === 'HEAD') {
                $includeBody = false;
            }

            if (! $includeBody) {
                $snapshot['headers'] = $this->withoutHeaders($snapshot['headers'], self::BODY_HEADERS);
                $snapshot['body'] = null;
            }

            $currentUrl = $nextUrl;
            $currentMethod = $nextMethod;
        }
    }

    /**
     * @param  array{headers: array<string, string>, query: array<string, scalar|null>, body: array{type: string, payload: mixed, contentType: ?string}|null}  $snapshot
     *
     * @throws OutboundHttpException
     */
    private function dispatch(
        string $method,
        AuthorizedDestination $destination,
        array $snapshot,
        bool $includeBody,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
        HttpRequestOptions $options,
    ): Response {
        $authorisedUrl = $this->urlWithQuery($destination->normalizedUrl, $snapshot['query']);

        $pending = $this->http
            ->withHeaders($snapshot['headers'])
            ->timeout($timeoutSeconds)
            ->connectTimeout($connectTimeoutSeconds)
            ->withOptions($this->transportOptions($destination));

        // Guzzle 7 honours a request-level `handler` from factory globalOptions
        // before our curl wrap runs — strip it so setHandler wins on both majors.
        $this->stripRequestHandlerOption($pending);

        $pending->setHandler($this->enforcingHandler(
            $authorisedUrl,
            $destination,
            $options,
            $timeoutSeconds,
            $connectTimeoutSeconds,
        ));

        $sendOptions = [];

        if ($includeBody && $snapshot['body'] !== null) { // @pest-mutate-ignore: BooleanAndToBooleanOr
            [$pending, $sendOptions] = $this->applyBody($pending, $snapshot['body']);
        }

        // The client's own refusals thrown inside the handler stack (a body
        // over max_response_bytes, which a receiver can provoke) are created
        // again here, as a local value, never passed on: a trace from inside
        // carries the frames' arguments, which hold the egress proxy URL with
        // its credentials. None of them carries a transport error kind.
        try {
            return $pending->send($method, $authorisedUrl, $sendOptions);
        } catch (OutboundHttpException $exception) {
            throw new OutboundHttpException($exception->getMessage());
        } catch (ConnectionException|GuzzleException|InvalidArgumentException $exception) {
            for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof OutboundHttpException) {
                    throw new OutboundHttpException($previous->getMessage());
                }
            }

            $transportErrorKind = self::transportErrorKindOf($exception);

            // A fixed message per kind: the transport's own text names the
            // host, the pinned address and the URL.
            throw OutboundHttpException::forTransport(
                $transportErrorKind,
                match ($transportErrorKind) {
                    OutboundTransportErrorKindEnum::Timeout => 'Outbound request failed: timeout.',
                    OutboundTransportErrorKindEnum::Tls => 'Outbound request failed: TLS error.',
                    OutboundTransportErrorKindEnum::Dns => 'Outbound request failed: DNS error.',
                    OutboundTransportErrorKindEnum::Connection => 'Outbound request failed: connection error.',
                },
                new RuntimeException('Underlying transport error.'),
            );
        }
    }

    /**
     * The curl errno decides. Guzzle 7 and 8 both start the message with
     * "cURL error N:" (8 no longer has a handler context), and Laravel's
     * wrapper keeps that message. Anything without one (a reset socket, a
     * stray exception) is a connection error.
     */
    private static function transportErrorKindOf(\Throwable $exception): OutboundTransportErrorKindEnum
    {
        $curlErrno = null;

        for ($link = $exception; $link !== null && $curlErrno === null; $link = $link->getPrevious()) {
            if (preg_match('/^cURL error (\d+):/', $link->getMessage(), $errnoMatch) === 1) {
                $curlErrno = (int) $errnoMatch[1];
            }
        }

        return match ($curlErrno) {
            28 => OutboundTransportErrorKindEnum::Timeout,
            6 => OutboundTransportErrorKindEnum::Dns,
            // SSL connect, peer verification, client certificate, cipher, CA
            // certificate, CA bundle, certificate status.
            35, 51, 58, 59, 60, 77, 91 => OutboundTransportErrorKindEnum::Tls,
            default => OutboundTransportErrorKindEnum::Connection,
        };
    }

    /**
     * @param  array{type: string, payload: mixed, contentType: ?string}  $body
     * @return array{0: PendingRequest, 1: array<string, mixed>}
     */
    private function applyBody(PendingRequest $pending, array $body): array
    {
        $payload = $body['payload'];

        return match ($body['type']) {
            'raw' => [
                $pending->withBody(
                    is_string($payload) ? $payload : throw new InvalidArgumentException('Raw body must be a string.'),
                    $body['contentType'] ?? 'application/octet-stream', // @pest-mutate-ignore: CoalesceRemoveLeft
                ),
                [],
            ],
            'json' => [
                $pending->asJson(),
                ['json' => is_array($payload) ? $payload : throw new InvalidArgumentException('JSON body must be an array.')],
            ],
            'form' => [
                $pending->asForm(),
                ['form_params' => is_array($payload) ? $payload : throw new InvalidArgumentException('Form body must be an array.')],
            ],
            'multipart' => [
                $pending->asMultipart(),
                ['multipart' => is_array($payload) ? $payload : throw new InvalidArgumentException('Multipart body must be an array.')],
            ],
            default => throw new InvalidArgumentException('Unknown outbound body type.'),
        };
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function urlWithQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $uri = new Uri($url);

        foreach ($query as $key => $value) {
            $uri = Uri::withQueryValue(
                $uri,
                (string) $key, // @pest-mutate-ignore: RemoveStringCast
                $value === null ? null : (string) $value, // @pest-mutate-ignore: RemoveStringCast
            );
        }

        return (string) $uri;
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOptions(AuthorizedDestination $destination): array
    {
        // allow_redirects must be false on the PendingRequest options so Guzzle's
        // RedirectMiddleware (wrapped outside our handler by HandlerStack::create)
        // does not follow Locations past the authorised URI check. Handler
        // re-asserts the same flags; item removals here are equivalent under tests.
        return [ // @pest-mutate-ignore: RemoveArrayItem
            'allow_redirects' => false,
            'proxy' => '', // @pest-mutate-ignore: EmptyStringToNotEmpty
            'stream' => false, // @pest-mutate-ignore: FalseToTrue
            'verify' => true, // @pest-mutate-ignore: TrueToFalse
            'decode_content' => false, // @pest-mutate-ignore: FalseToTrue
            'curl' => $destination->guzzleCurlOptions()['curl'],
            'headers' => [ // @pest-mutate-ignore: RemoveArrayItem
                'Accept-Encoding' => 'identity',
            ],
        ];
    }

    /**
     * @return array{on_headers: callable, progress: callable}
     */
    private function sizeLimitOptions(int $max): array
    {
        return [
            'on_headers' => static function (ResponseInterface $response) use ($max): void {
                $length = $response->getHeaderLine('Content-Length');

                if ($length !== '' && ctype_digit($length) && (int) $length > $max) {
                    throw new OutboundHttpException('Response exceeds max_response_bytes.');
                }
            },
            'progress' => static function (int|float $downloadTotal, int|float $downloadedBytes) use ($max): void {
                if ($downloadedBytes > $max || $downloadTotal > $max) {
                    throw new OutboundHttpException('Response exceeds max_response_bytes.');
                }
            },
        ];
    }

    /**
     * Innermost curl-handler wrap: re-asserts URI, pin, proxy, redirects, stream,
     * verify, and timeouts on every transfer.
     */

    /**
     * Remove a poisoned request-level handler inherited from factory globalOptions.
     *
     * Fail closed if Laravel renames/removes PendingRequest::$options — silent
     * skip would re-open the Guzzle 7 handler bypass.
     */
    private function stripRequestHandlerOption(object $pending): void
    {
        $reflection = new ReflectionObject($pending);

        if (! $reflection->hasProperty('options')) {
            throw new OutboundHttpException(
                'PendingRequest options property is missing; refusing to send without handler isolation.',
            );
        }

        $property = $reflection->getProperty('options');
        $options = $property->getValue($pending);

        if (! is_array($options)) {
            throw new OutboundHttpException(
                'PendingRequest options property is not an array; refusing to send without handler isolation.',
            );
        }

        if (! array_key_exists('handler', $options)) {
            return;
        }

        unset($options['handler']);
        $property->setValue($pending, $options);
    }

    private function enforcingHandler(
        string $authorisedUrl,
        AuthorizedDestination $destination,
        HttpRequestOptions $options,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
    ): callable {
        $inner = is_callable($this->transferHandler) ? $this->transferHandler : new CurlHandler;

        return function (RequestInterface $request, array $requestOptions) use (
            $inner,
            $authorisedUrl,
            $destination,
            $options,
            $timeoutSeconds,
            $connectTimeoutSeconds,
        ) {
            if ((string) $request->getUri() !== $authorisedUrl) {
                throw new OutboundHttpException('Request URI was altered after authorisation.');
            }

            unset($requestOptions['handler']);

            $requestOptions['allow_redirects'] = false;
            // The deployment's egress proxy or none; never the caller's or the
            // environment's.
            $requestOptions['proxy'] = $this->egressProxy?->url() ?? '';
            $requestOptions['stream'] = false;
            $requestOptions['verify'] = true;
            $requestOptions['decode_content'] = false;
            $requestOptions['timeout'] = $timeoutSeconds;
            $requestOptions['connect_timeout'] = $connectTimeoutSeconds;

            $curl = is_array($requestOptions['curl'] ?? null) ? $requestOptions['curl'] : []; // @pest-mutate-ignore: TernaryNegated,CoalesceRemoveLeft
            unset($curl[CURLOPT_PROXY], $curl[CURLOPT_NOPROXY], $curl[CURLOPT_HTTPPROXYTUNNEL]);
            $curl[CURLOPT_CONNECT_TO] = [$destination->curlConnectToEntry];

            if ($this->egressProxy !== null) {
                // Tunnel even plain http: the proxy then receives `CONNECT` to
                // the address the guard pinned, never a URL or a name to resolve.
                $curl[CURLOPT_HTTPPROXYTUNNEL] = true;
            }
            $requestOptions['curl'] = $curl;

            $request = $request->withHeader('Accept-Encoding', 'identity');

            if ($options->maxResponseBytes !== null) {
                $requestOptions = [...$requestOptions, ...$this->sizeLimitOptions($options->maxResponseBytes)];
            }

            return $inner($request, $requestOptions);
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

        // UriResolver emits absolute URLs; parse_url does not return false for them.
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) { // @pest-mutate-ignore: FalseToTrue,BooleanOrToBooleanAnd
            throw new OutboundHttpException('Redirect Location is not an absolute http(s) URL.');
        }

        $scheme = strtolower((string) $parts['scheme']); // @pest-mutate-ignore: RemoveStringCast,UnwrapStrtolower

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundHttpException('Redirect Location scheme is not allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            throw new OutboundHttpException('Redirect Location must not contain credentials.');
        }

        return $resolved;
    }

    /**
     * @throws OutboundHttpException
     */
    private function assertNoHttpsDowngrade(string $fromUrl, string $toUrl): void
    {
        $fromScheme = strtolower((string) (parse_url($fromUrl, PHP_URL_SCHEME) ?? ''));
        $toScheme = strtolower((string) (parse_url($toUrl, PHP_URL_SCHEME) ?? ''));

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
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return sprintf('%s://%s:%d', $scheme, $host, $port); // @pest-mutate-ignore: ConcatRemoveLeft,ConcatRemoveRight,ConcatSwitchSides
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function withoutHeaders(array $headers, array $names): array
    {
        $filtered = [];

        foreach ($headers as $name => $value) {
            foreach ($names as $drop) {
                if (strcasecmp($name, $drop) === 0) {
                    continue 2;
                }
            }

            $filtered[$name] = $value;
        }

        return $filtered;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function onlyHeaders(array $headers, array $names): array
    {
        $filtered = [];

        foreach ($headers as $name => $value) {
            foreach ($names as $keep) {
                if (strcasecmp($name, $keep) === 0) {
                    $filtered[$name] = $value;

                    continue 2;
                }
            }
        }

        return $filtered;
    }

    private function methodAfterRedirect(string $method, int $status): string
    {
        // Method is already uppercased at the start of send().
        $method = strtoupper($method); // @pest-mutate-ignore: UnwrapStrtoupper

        if ($status === 303) {
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
        if ($options->timeoutSeconds < 1 || $options->connectTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Outbound timeouts must be at least 1 second.');
        }

        if ($this->limits->totalDeadlineSeconds < 1) {
            throw new InvalidArgumentException('Outbound total deadline must be at least 1 second.');
        }

        if ($options->timeoutSeconds > $this->limits->maxTimeoutSeconds) {
            throw new InvalidArgumentException('Outbound timeout exceeds configured max_timeout_seconds.');
        }

        if ($options->connectTimeoutSeconds > $this->limits->maxConnectTimeoutSeconds) {
            throw new InvalidArgumentException('Outbound connect timeout exceeds configured max_connect_timeout_seconds.');
        }
    }

    private function now(): float
    {
        if (is_callable($this->clock)) {
            return (float) ($this->clock)(); // @pest-mutate-ignore: RemoveDoubleCast
        }

        return microtime(true);
    }
}
