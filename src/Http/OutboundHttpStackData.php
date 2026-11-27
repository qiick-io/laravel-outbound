<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use Illuminate\Http\Client\Factory as HttpFactory;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;

/**
 * An isolated URL guard and pinned client sharing one destination policy.
 *
 * @api
 */
final readonly class OutboundHttpStackData
{
    private function __construct(
        public RemoteUrlGuard $guard,
        public PinnedHttpClient $client,
    ) {}

    public static function make(
        HostResolver $hostResolver,
        bool $allowPrivateHosts = false,
        bool $httpsOnly = true,
        OutboundHttpLimits $limits = new OutboundHttpLimits,
        ?HttpFactory $httpFactory = null,
    ): OutboundHttpStackData {
        $guard = new RemoteUrlGuard($hostResolver, $allowPrivateHosts, $httpsOnly);
        $client = new PinnedHttpClient($httpFactory ?? new HttpFactory, $guard, $limits);

        return new self($guard, $client);
    }
}
