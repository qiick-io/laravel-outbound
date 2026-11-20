<?php

declare(strict_types=1);

namespace Qiick\Outbound\Testing;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Builds an HttpFactory for {@see \Qiick\Outbound\Http\PinnedHttpClient} tests.
 *
 * {@see \Illuminate\Support\Facades\Http::fake()} and shared-factory global
 * middleware do not reach PinnedHttpClient when the service provider binds a
 * private factory. Inject the factory returned here into PinnedHttpClient
 * (or bind it over the container binding in a test).
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
