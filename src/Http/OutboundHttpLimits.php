<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

/**
 * Config-owned transport ceilings for {@see PinnedHttpClient}.
 * Per-call {@see HttpRequestOptions} may only choose values at or below these.
 *
 * @api
 */
final readonly class OutboundHttpLimits
{
    public function __construct(
        public int $maxTimeoutSeconds = 60,
        public int $maxConnectTimeoutSeconds = 30,
        public int $totalDeadlineSeconds = 30,
    ) {}
}
