<?php

declare(strict_types=1);

use Qiick\Outbound\Ssrf\DnsHostResolver;

/*
 * Every case goes through the seams: Pest never calls live DNS.
 */

it('asks DNS for A and AAAA records of the host', function (): void {
    $askedFor = null;
    $resolver = new DnsHostResolver(
        dnsGetRecord: function (string $host, int $type) use (&$askedFor): array {
            $askedFor = [$host, $type];

            return [['ip' => '93.184.216.34']];
        },
    );

    $resolver->resolve('example.test');

    expect($askedFor)->toBe(['example.test', DNS_A | DNS_AAAA]);
});

it('keeps each A and AAAA address once, in order, and skips anything else', function (): void {
    $resolver = new DnsHostResolver(
        dnsGetRecord: fn (): array => [
            'not a record',
            ['type' => 'CNAME', 'target' => 'other.test'],
            ['ip' => '93.184.216.34'],
            ['ip' => 123],
            ['ipv6' => '2001:db8::1'],
            ['ip' => '93.184.216.34'],
        ],
        getHostByNameL: fn (): never => throw new RuntimeException('DNS answered: no fallback.'),
    );

    expect($resolver->resolve('example.test'))->toBe(['93.184.216.34', '2001:db8::1']);
});

it('falls back to gethostbynamel only when DNS gave no address, keeping its strings once', function (): void {
    $resolver = new DnsHostResolver(
        dnsGetRecord: fn (): bool => false,
        getHostByNameL: fn (string $host): array => $host === 'example.test' ? ['93.184.216.34', 7, '93.184.216.35', '93.184.216.34'] : [],
    );

    expect($resolver->resolve('example.test'))->toBe(['93.184.216.34', '93.184.216.35']);
});

it('answers nothing when neither DNS nor the fallback has an address', function (): void {
    $resolver = new DnsHostResolver(dnsGetRecord: fn (): array => [['type' => 'MX']], getHostByNameL: fn (): bool => false);

    expect($resolver->resolve('example.test'))->toBe([]);
});
