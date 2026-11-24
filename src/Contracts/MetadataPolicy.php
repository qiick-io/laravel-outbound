<?php

declare(strict_types=1);

namespace Qiick\Outbound\Contracts;

/**
 * Metadata hosts a product adds to the ones RemoteUrlGuard always refuses,
 * also with private hosts allowed (standards review S4): an internal
 * metadata or control-plane address of its own platform. The guard takes the
 * union with its own list, so a policy can never unblock a host.
 *
 * Bind an implementation in the container; the guard normalises each entry
 * (a lowercased name without a trailing dot, a canonical IP address).
 *
 * @api
 */
interface MetadataPolicy
{
    /**
     * @return list<string> host names or IP addresses
     */
    public function additionalMetadataHosts(): array;
}
