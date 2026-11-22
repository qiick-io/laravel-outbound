<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

/**
 * Optional product port policy. Null on UrlGuardOptions means any port after
 * the SSRF checks; the chosen port is always part of the CONNECT_TO pin.
 *
 * @api
 */
interface PortPolicy
{
    public function allows(int $port, string $scheme): bool;
}
