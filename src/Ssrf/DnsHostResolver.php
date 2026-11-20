<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

use Qiick\Outbound\Contracts\HostResolver;

/**
 * System DNS HostResolver (A + AAAA, then gethostbynamel). Production default;
 * tests inject FakeHostResolver instead — never call this from Pest.
 */
final class DnsHostResolver implements HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            $addresses = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($addresses));
    }
}
