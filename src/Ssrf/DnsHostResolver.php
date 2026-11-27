<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

use Closure;
use Qiick\Outbound\Contracts\HostResolver;

/**
 * System DNS HostResolver (A + AAAA, then gethostbynamel). Production default;
 * unit tests inject FakeHostResolver or seam closures — never call live DNS from Pest.
 *
 * @api
 */
final class DnsHostResolver implements HostResolver
{
    /** @var Closure(string, int): mixed */
    private readonly Closure $dnsGetRecord;

    /** @var Closure(string): mixed */
    private readonly Closure $getHostByNameL;

    /**
     * @param  (Closure(string, int): mixed)|null  $dnsGetRecord  defaults to dns_get_record()
     * @param  (Closure(string): mixed)|null  $getHostByNameL  defaults to gethostbynamel()
     */
    public function __construct(?Closure $dnsGetRecord = null, ?Closure $getHostByNameL = null)
    {
        // dns_get_record() warns when the lookup fails, and Laravel turns a
        // warning into an exception: a failed lookup is no answer instead.
        $this->dnsGetRecord = $dnsGetRecord ?? static fn (string $host, int $type): mixed => @dns_get_record($host, $type);
        $this->getHostByNameL = $getHostByNameL ?? gethostbynamel(...);
    }

    /**
     * Every A and AAAA answer once, in order; gethostbynamel() only when DNS
     * gave none.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = ($this->dnsGetRecord)($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = is_array($record) ? $record['ip'] ?? $record['ipv6'] ?? null : null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $fallback = ($this->getHostByNameL)($host);
            $addresses = is_array($fallback) ? array_filter($fallback, is_string(...)) : [];
        }

        return array_values(array_unique($addresses));
    }
}
