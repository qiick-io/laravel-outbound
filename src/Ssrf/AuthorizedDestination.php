<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

use RuntimeException;

/**
 * A URL the SSRF guard accepted, with the single address to pin via
 * CURLOPT_CONNECT_TO (never CURLOPT_RESOLVE alone).
 *
 * Environment HTTP(S) proxies (`http_proxy` / `HTTPS_PROXY` / `ALL_PROXY`) can
 * bypass CONNECT_TO pinning until `PinnedHttpClient` disables proxies on the
 * PendingRequest. Do not call guzzleCurlOptions() from a client that still
 * honours those proxies.
 *
 * @api
 */
final readonly class AuthorizedDestination
{
    public function __construct(
        public string $normalizedUrl,
        public string $host,
        public int $port,
        public string $pinnedAddress,
        public string $curlConnectToEntry,
    ) {}

    /**
     * CONNECT_TO only — never CURLOPT_RESOLVE. Always disables transport redirects.
     *
     * @return array{allow_redirects: false, curl: array<int, mixed>}
     *
     * @throws RuntimeException when CURLOPT_CONNECT_TO is undefined (fail closed)
     */
    public function guzzleCurlOptions(): array
    {
        if (! defined('CURLOPT_CONNECT_TO')) {
            throw new RuntimeException('CURLOPT_CONNECT_TO is required for SSRF pinning.');
        }

        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_CONNECT_TO => [$this->curlConnectToEntry],
            ],
        ];
    }
}
