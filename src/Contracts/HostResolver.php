<?php

declare(strict_types=1);

namespace Qiick\Outbound\Contracts;

/**
 * Resolves a hostname to IPv4 and IPv6 addresses for the SSRF guard.
 *
 * @api
 */
interface HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
