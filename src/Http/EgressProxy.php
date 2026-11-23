<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use InvalidArgumentException;
use LogicException;

/**
 * The deployment's egress proxy (`outbound.http.egress_proxy`): a filtering
 * proxy every outbound request goes through, as Standard Webhooks "Server side
 * request forgery (SSRF)" recommends (standards review R3).
 *
 * Only `http://` and `https://` proxies: {@see PinnedHttpClient} forces them
 * into tunnel mode, so they receive `CONNECT` to the address the guard
 * approved and never resolve a name. A SOCKS proxy could resolve names
 * itself, so it is refused. The proxy is trusted infrastructure: the guard
 * does not check its own address. Its credentials never leave this object
 * as text: not in a string cast, a dump, serialization or an exception.
 *
 * @api
 */
final class EgressProxy
{
    private function __construct(
        #[\SensitiveParameter]
        private readonly string $url,
        private readonly string $redactedUrl,
    ) {}

    /**
     * @throws InvalidArgumentException when the URL is not an http(s) proxy
     *                                  with a host and nothing after the port;
     *                                  the message never repeats the value
     */
    public static function fromUrl(
        #[\SensitiveParameter]
        string $url,
    ): self {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || array_diff(array_keys($parts), ['scheme', 'host', 'port', 'user', 'pass']) !== []
            || ! str_starts_with($url, $parts['scheme'].'://')
            || str_ends_with($url, '/')
        ) {
            throw new InvalidArgumentException('outbound.http.egress_proxy must be an http:// or https:// proxy URL with a host and nothing after the port.');
        }

        $hostAndPort = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $credentials = isset($parts['user']) ? '[REDACTED]@' : '';

        return new self($url, "{$parts['scheme']}://{$credentials}{$hostAndPort}");
    }

    /**
     * The URL with its credentials, for the transport only.
     */
    public function url(): string
    {
        return $this->url;
    }

    public function __toString(): string
    {
        return $this->redactedUrl;
    }

    /**
     * @return array{url: string}
     */
    public function __debugInfo(): array
    {
        return ['url' => $this->redactedUrl];
    }

    /**
     * @return never
     *
     * @throws LogicException always: a serialized proxy would carry its credentials
     */
    public function __serialize(): array
    {
        throw new LogicException('An egress proxy must not be serialized.');
    }
}
