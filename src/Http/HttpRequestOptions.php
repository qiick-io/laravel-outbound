<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use Qiick\Outbound\Ssrf\UrlGuardOptions;

/**
 * Per-call options for {@see PinnedHttpClient}. Defaults match `outbound.http`.
 *
 * Timeouts must be ≥ 1 second and ≤ the config-owned
 * {@see OutboundHttpLimits} ceilings. The total redirect-chain deadline is
 * never raisable from per-call options.
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
        /**
         * Cross-origin redirects are refused by default. When true, only
         * 301/302/303 are allowed across origins (body is dropped); 307/308
         * cross-origin is always refused because they require resending the body.
         */
        public bool $allowCrossOriginRedirects = false,
    ) {}
}
