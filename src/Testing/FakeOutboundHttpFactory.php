<?php

declare(strict_types=1);

namespace Qiick\Outbound\Testing;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Qiick\Outbound\Http\OutboundHttpStackData;
use Qiick\Outbound\Http\PinnedHttpClient;

/**
 * Builds an HttpFactory for {@see PinnedHttpClient} tests.
 *
 * The shared facade fake does not reach the service provider's private client.
 * Pass the factory returned here to {@see OutboundHttpStackData::make()}.
 *
 * @api
 */
final class FakeOutboundHttpFactory
{
    /**
     * @param  array<string, mixed>|Closure|null  $callback
     */
    public static function fake(array|Closure|null $callback = null): HttpFactory
    {
        $factory = new HttpFactory;
        $factory->fake($callback);

        return $factory;
    }

    public static function make(): HttpFactory
    {
        return new HttpFactory;
    }
}
