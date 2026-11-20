<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use Qiick\Outbound\Ssrf\UrlGuardOptions;

/**
 * Per-call options for {@see PinnedHttpClient}. Defaults match `outbound.http`
 * and Externa / Signaculum / oidc delivery timeouts.
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
    ) {}
}
