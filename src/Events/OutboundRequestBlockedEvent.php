<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

use Qiick\Outbound\Http\PinnedHttpClient;

/**
 * The SSRF guard refused a request of {@see PinnedHttpClient}
 * (design §3.5, standards review M13): for alerting on attempts to reach
 * internal addresses. The destination's host and the guard's reason only:
 * a path or a query can carry a token, so neither is kept.
 *
 * @api
 */
final readonly class OutboundRequestBlockedEvent
{
    public function __construct(
        public string $host,
        public string $reason,
    ) {}
}
