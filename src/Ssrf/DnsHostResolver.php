<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

use Closure;
use Qiick\Outbound\Contracts\HostResolver;

/**
 * System DNS HostResolver (A + AAAA, then gethostbynamel). Production default;
 * unit tests inject FakeHostResolver or seam closures — never call live DNS from Pest.
 */
final class DnsHostResolver implements HostResolver
{
    /**
     * @param  (Closure(string, int): mixed)|null  $dnsGetRecord
     * @param  (Closure(string): mixed)|null  $getHostByNameL
     */
    public function __construct(
        private ?Closure $dnsGetRecord = null,
        private ?Closure $getHostByNameL = null,
    ) {}

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = $this->dnsGetRecord !== null
            ? ($this->dnsGetRecord)($host, DNS_A | DNS_AAAA)
            : @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            if (! is_array($record)) {
                continue;
            }

            $address = null;

            if (isset($record['ip']) && is_string($record['ip'])) {
                $address = $record['ip'];
            } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $address = $record['ipv6'];
            }

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $fallback = $this->getHostByNameL !== null
                ? ($this->getHostByNameL)($host)
                : @gethostbynamel($host);

            if (is_array($fallback)) {
                foreach ($fallback as $address) {
                    if (is_string($address)) {
                        $addresses[] = $address;
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
