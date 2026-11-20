<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Qiick\Outbound\Ssrf\AuthorizedDestination;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;

/**
 * Laravel HTTP client that always SSRF-guards the URL, pins with
 * CURLOPT_CONNECT_TO, disables proxies, and never auto-follows redirects.
 * When {@see HttpRequestOptions::$followRedirects} is on, each Location is
 * re-authorised and re-pinned (closes DNS rebinding across hops).
 *
 * @api
 */
final class PinnedHttpClient
{
    public function __construct(
        private HttpFactory $http,
        private RemoteUrlGuard $guard,
    ) {}

    /**
     * @param  callable(PendingRequest, string, string): Response  $send  request, url, method
     *
     * @throws UnsafeUrl
     * @throws OutboundHttpException
     */
    public function send(
        string $method,
        string $url,
        callable $send,
        HttpRequestOptions $options = new HttpRequestOptions,
    ): Response {
        $currentUrl = $url;
        $currentMethod = strtoupper($method);
        $hops = 0;

        while (true) {
            $destination = $this->guard->authorize($currentUrl, $options->urlOptions);
            $response = $this->dispatch($currentMethod, $currentUrl, $destination, $send, $options);

            if (! $this->shouldFollowRedirect($response, $options)) {
                $this->assertWithinSizeLimit($response, $options);

                return $response;
            }

            $hops++;

            if ($hops > $options->maxRedirects) {
                throw new OutboundHttpException('Redirect hop limit exceeded.');
            }

            $currentUrl = $this->nextRedirectUrl($currentUrl, $response);
            $currentMethod = $this->methodAfterRedirect($currentMethod, $response->status());
        }
    }

    /**
     * @param  callable(PendingRequest, string, string): Response  $send
     *
     * @throws OutboundHttpException
     */
    private function dispatch(
        string $method,
        string $url,
        AuthorizedDestination $destination,
        callable $send,
        HttpRequestOptions $options,
    ): Response {
        $request = $this->http
            ->withHeaders($options->headers)
            ->timeout($options->timeoutSeconds)
            ->connectTimeout($options->connectTimeoutSeconds)
            ->withOptions($this->transportOptions($destination));

        try {
            return $send($request, $url, $method);
        } catch (ConnectionException $exception) {
            throw new OutboundHttpException('Outbound request failed: connection error.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOptions(AuthorizedDestination $destination): array
    {
        $options = $destination->guzzleCurlOptions();
        $curl = $options['curl'];

        // Empty CURLOPT_PROXY + NOPROXY=* defeat env http_proxy / HTTPS_PROXY.
        $curl[CURLOPT_PROXY] = '';
        $curl[CURLOPT_NOPROXY] = '*';

        return [
            'allow_redirects' => false,
            'proxy' => [
                'http' => null,
                'https' => null,
                'no' => ['*'],
            ],
            'curl' => $curl,
        ];
    }

    private function shouldFollowRedirect(Response $response, HttpRequestOptions $options): bool
    {
        if (! $options->followRedirects || $options->maxRedirects < 1) {
            return false;
        }

        $status = $response->status();

        return $status >= 300 && $status < 400;
    }

    /**
     * @throws OutboundHttpException
     * @throws UnsafeUrl
     */
    private function nextRedirectUrl(string $currentUrl, Response $response): string
    {
        $location = trim($response->header('Location'));

        if ($location === '') {
            throw new OutboundHttpException('Redirect response is missing a Location header.');
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        } catch (InvalidArgumentException $exception) {
            throw new OutboundHttpException('Redirect Location is not a valid URI.', 0, $exception);
        }

        $parts = parse_url($resolved);

        // parse_url does not return false for the absolute URLs UriResolver emits.
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) { // @pest-mutate-ignore: FalseToTrue,BooleanOrToBooleanAnd
            throw new OutboundHttpException('Redirect Location is not an absolute http(s) URL.');
        }

        $scheme = strtolower((string) $parts['scheme']); // @pest-mutate-ignore: RemoveStringCast

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundHttpException('Redirect Location scheme is not allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundHttpException('Redirect Location must not contain credentials.');
        }

        return $resolved;
    }

    private function methodAfterRedirect(string $method, int $status): string
    {
        // Method is already uppercased at the start of send(); cast is a safety net.
        $method = strtoupper($method); // @pest-mutate-ignore: UnwrapStrtoupper

        if ($status === 303) {
            return 'GET';
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
}
