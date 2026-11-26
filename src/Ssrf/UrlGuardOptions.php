<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

/**
 * Per-call options for RemoteUrlGuard. They can only make a call stricter
 * than the guard's own settings: `httpsOnly` adds to the guard's, and
 * `allowPrivateHosts` narrows it (false refuses private hosts the guard
 * allows; true or null keep the guard's setting, which stays the ceiling).
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
        public ?bool $allowPrivateHosts = null,
        public bool $enforceHostAllowlist = false,
        public array $hostAllowlist = [],
        public ?PortPolicy $portPolicy = null,
    ) {}
}
