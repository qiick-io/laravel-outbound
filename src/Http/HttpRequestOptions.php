<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use Qiick\Outbound\Ssrf\UrlGuardOptions;

/**
 * Per-call options for {@see PinnedHttpClient}. Defaults match `outbound.http`
 * and Externa / Signaculum / oidc delivery timeouts.
 *
 * Timeouts must be ≥ 1 second and ≤ the configured caps. {@see $totalDeadlineSeconds}
 * bounds the whole redirect chain.
 *
 * @api
 */
final readonly class HttpRequestOptions
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $timeoutSeconds = 5,
        public int $connectTimeoutSeconds = 3,
        public bool $followRedirects = false,
        public int $maxRedirects = 0,
        public ?int $maxResponseBytes = 1_048_576,
        public array $headers = [],
        public UrlGuardOptions $urlOptions = new UrlGuardOptions,
        public int $maxTimeoutSeconds = 60,
        public int $maxConnectTimeoutSeconds = 30,
        public int $totalDeadlineSeconds = 30,
        /**
         * Cross-origin redirects are refused by default so a caller `$send`
         * body cannot be replayed to another host. When true, sensitive hop
         * headers are stripped and each Location is still re-authorised.
         */
        public bool $allowCrossOriginRedirects = false,
    ) {}
}
