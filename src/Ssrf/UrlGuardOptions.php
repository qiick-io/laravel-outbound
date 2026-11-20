<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

/**
 * Per-call options for RemoteUrlGuard. Constructor defaults on the guard still
 * apply when a field here is left at its default and the product did not set a
 * stronger default on the guard itself.
 *
 * @api
 */
final readonly class UrlGuardOptions
{
    /**
     * @param  list<string>  $hostAllowlist
     */
    public function __construct(
        public bool $httpsOnly = false,
        public bool $allowPrivateHosts = false,
        public bool $enforceHostAllowlist = false,
        public array $hostAllowlist = [],
        public ?PortPolicy $portPolicy = null,
    ) {}
}
