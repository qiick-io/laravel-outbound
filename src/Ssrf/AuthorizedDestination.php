<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

/**
 * A URL the SSRF guard accepted, with the single address to pin via
 * CURLOPT_CONNECT_TO (never CURLOPT_RESOLVE alone).
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
     * CONNECT_TO only — never CURLOPT_RESOLVE.
     *
     * @return array{curl: array<int, mixed>}
     */
    public function guzzleCurlOptions(): array
    {
        if (! defined('CURLOPT_CONNECT_TO')) {
            return ['curl' => []];
        }

        return [
            'curl' => [
                CURLOPT_CONNECT_TO => [$this->curlConnectToEntry],
            ],
        ];
    }
}
