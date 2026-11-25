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
    /**
     * The URLs with their credentials, kept outside the instances: dump(),
     * dd(), Telescope, an `(array)` cast and var_export() read every
     * instance property, whatever __debugInfo() says.
     *
     * @var \WeakMap<self, string>|null
     */
    private static ?\WeakMap $urlsByProxy = null;

    private function __construct(
        #[\SensitiveParameter]
        string $url,
        private readonly string $redactedUrl,
    ) {
        self::urlsByProxy()[$this] = $url;
    }

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

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! isset($parts['host'])) {
            throw self::invalidUrl();
        }

        $hostAndPort = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $credentials = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';

        // parse_url() tolerates what curl would read differently (a newline
        // it shows as `_` in the host, an empty or padded port, a path):
        // only a URL that is exactly its own parts is accepted.
        if ("{$parts['scheme']}://{$credentials}{$hostAndPort}" !== $url) {
            throw self::invalidUrl();
        }

        return new self($url, "{$parts['scheme']}://".($credentials === '' ? '' : '[REDACTED]@').$hostAndPort);
    }

    /**
     * The URL with its credentials, for the transport only.
     */
    public function url(): string
    {
        return self::urlsByProxy()[$this];
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

    /**
     * A clone would have no URL: the map holds the original only.
     */
    private function __clone() {}

    /**
     * @return \WeakMap<self, string>
     */
    private static function urlsByProxy(): \WeakMap
    {
        return self::$urlsByProxy ??= new \WeakMap;
    }

    /**
     * The message never repeats the value, which may hold credentials.
     */
    private static function invalidUrl(): InvalidArgumentException
    {
        return new InvalidArgumentException('outbound.http.egress_proxy must be an http:// or https:// proxy URL with a host and nothing after the port.');
    }
}
