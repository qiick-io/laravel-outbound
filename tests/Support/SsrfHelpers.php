<?php

declare(strict_types=1);

use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;

/**
 * @param  list<string>  $resolvedAddresses
 */
function ssrfGuardResolving(bool $allowPrivate = false, array $resolvedAddresses = ['93.184.216.34'], bool $httpsOnly = false): RemoteUrlGuard
{
    return new RemoteUrlGuard(
        new class($resolvedAddresses) implements HostResolver
        {
            /** @param  list<string>  $addresses */
            public function __construct(private array $addresses) {}

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        },
        $allowPrivate,
        $httpsOnly,
    );
}

function ssrfGuardThatMustNotResolve(bool $allowPrivate = false): RemoteUrlGuard
{
    return new RemoteUrlGuard(
        new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                throw new RuntimeException("Guard resolved an address literal as a name: {$host}");
            }
        },
        $allowPrivate,
    );
}
