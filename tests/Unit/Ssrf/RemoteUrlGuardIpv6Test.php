<?php

declare(strict_types=1);

use Qiick\Outbound\Ssrf\UnsafeUrl;

/**
 * IPv6 surface. Ported from qiick-io/laravel-oidc RemoteUrlGuardIpv6Test
 * (signaculum-core #181 / #189).
 */
it('keeps every spelling of the ipv6 metadata address blocked when private hosts are allowed', function (string $url): void {
    expect(fn () => ssrfGuardResolving(allowPrivate: true)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'canonical' => 'http://[fd00:ec2::254]/latest/meta-data/',
    'uncompressed' => 'http://[FD00:EC2:0:0:0:0:0:254]/latest/meta-data/',
    'leading zeroes' => 'http://[fd00:0ec2::0254]/latest/meta-data/',
    'mixed case' => 'http://[Fd00:Ec2::254]/latest/meta-data/',
    'with a port' => 'http://[fd00:ec2::254]:80/latest/meta-data/',
    'ipv4 mapped ec2 metadata' => 'http://[::ffff:169.254.169.254]/latest/meta-data/',
    'ipv4 mapped in hextets' => 'http://[::ffff:a9fe:a9fe]/latest/meta-data/',
    'ipv4 compatible ec2 metadata' => 'http://[::169.254.169.254]/latest/meta-data/',
    'alibaba ipv4 mapped' => 'http://[::ffff:100.100.100.200]/latest/meta-data/',
    'alibaba ipv4 mapped in hextets' => 'http://[::ffff:6464:64c8]/latest/meta-data/',
    'alibaba ipv4 mapped, upper case' => 'http://[::FFFF:6464:64C8]/latest/meta-data/',
    'alibaba ipv4 mapped, expanded' => 'http://[0:0:0:0:0:ffff:6464:64c8]/latest/meta-data/',
    'alibaba ipv4 compatible' => 'http://[::100.100.100.200]/latest/meta-data/',
    'alibaba ipv4 compatible in hextets' => 'http://[::6464:64c8]/latest/meta-data/',
    'oracle ipv4 mapped' => 'http://[::ffff:192.0.0.192]/latest/meta-data/',
    'oracle ipv4 mapped in hextets' => 'http://[::ffff:c000:c0]/latest/meta-data/',
    'oracle ipv4 mapped, upper case' => 'http://[::FFFF:C000:C0]/latest/meta-data/',
    'oracle ipv4 mapped, expanded' => 'http://[0:0:0:0:0:ffff:c000:c0]/latest/meta-data/',
    'oracle ipv4 compatible' => 'http://[::192.0.0.192]/latest/meta-data/',
    'oracle ipv4 compatible in hextets' => 'http://[::c000:c0]/latest/meta-data/',
]);

it('keeps the ipv6 metadata address blocked with private hosts off as well', function (): void {
    expect(fn () => ssrfGuardResolving()->authorize('https://[fd00:ec2::254]/latest/'))
        ->toThrow(UnsafeUrl::class);
});

it('refuses each blocked ipv6 range as a bracketed literal, by the guard and not the resolver', function (string $url): void {
    expect(fn () => ssrfGuardThatMustNotResolve()->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'loopback ::1' => 'https://[::1]/token',
    'unspecified ::' => 'https://[::]/token',
    'unspecified, long form' => 'https://[0:0:0:0:0:0:0:0]/token',
    'link local fe80::/10' => 'https://[fe80::1]/token',
    'link local, top of the range' => 'https://[febf:ffff::1]/token',
    'unique local fc00::/7' => 'https://[fc00::1]/token',
    'unique local, fd00::/8 half' => 'https://[fd00::1]/token',
    'multicast ff00::/8' => 'https://[ff02::1]/token',
    'documentation 2001:db8::/32' => 'https://[2001:db8::1]/token',
    'an explicit port is parsed off the host' => 'https://[fc00::1]:8443/token',
    'ipv4 mapped loopback' => 'https://[::ffff:127.0.0.1]/token',
    'ipv4 mapped private' => 'https://[::ffff:10.0.0.5]/token',
    'ipv4 compatible private' => 'https://[::10.0.0.5]/token',
]);

it('lets the admin reach a private ipv6 destination once they opt in, as for ipv4', function (string $url): void {
    expect(ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize($url)->pinnedAddress)
        ->not->toBeEmpty();
})->with([
    'loopback' => 'http://[::1]/token',
    'unique local' => 'http://[fc00::1]:8443/token',
    'link local' => 'http://[fe80::1]/token',
    'ipv4 mapped private' => 'http://[::ffff:10.0.0.5]/token',
]);

it('refuses a bracketed host that is not an ipv6 literal instead of treating it as a dns name', function (string $url): void {
    expect(fn () => ssrfGuardResolving(allowPrivate: true)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'a zone id, which curl would still connect to' => 'http://[fe80::1%25en0]/token',
    'a raw zone id' => 'http://[fe80::1%en0]/token',
    'letters that are not hextets' => 'http://[not:an:ip]/token',
    'a hostname in brackets' => 'http://[idp.example.com]/token',
    'an ipv4 address in brackets' => 'http://[127.0.0.1]/token',
    'empty brackets' => 'http://[]/token',
    'too many hextets' => 'http://[1:2:3:4:5:6:7:8:9]/token',
]);

it('never asks the resolver about a bracketed host at all', function (string $url): void {
    expect(fn () => ssrfGuardThatMustNotResolve()->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'loopback' => 'https://[::1]/token',
    'metadata' => 'https://[fd00:ec2::254]/latest/',
    'a zone id' => 'https://[fe80::1%25en0]/token',
    'not an address' => 'https://[not:an:ip]/token',
]);

it('still authorises a public ipv6 literal and pins it to itself', function (): void {
    expect(ssrfGuardThatMustNotResolve()->authorize('https://[2606:2800:220:1:248:1893:25c8:1946]/token')->pinnedAddress)
        ->toBe('2606:2800:220:1:248:1893:25c8:1946');
});

it('still authorises a public ipv4 mapped literal', function (): void {
    expect(ssrfGuardThatMustNotResolve()->authorize('https://[::ffff:93.184.216.34]/token')->pinnedAddress)
        ->toBe('::ffff:93.184.216.34');
});

it('still pins a hostname that resolves to ipv6, brackets and all', function (): void {
    $destination = ssrfGuardResolving(false, ['2606:2800:220:1:248:1893:25c8:1946'])
        ->authorize('https://v6.example.com/token');

    expect($destination->pinnedAddress)->toBe('2606:2800:220:1:248:1893:25c8:1946')
        ->and($destination->curlConnectToEntry)->toBe('::[2606:2800:220:1:248:1893:25c8:1946]:443');
});

it('refuses a hostname that resolves to a disallowed ipv6 address', function (array $addresses): void {
    expect(fn () => ssrfGuardResolving(false, $addresses)->authorize('https://rebind.example.com/token'))
        ->toThrow(UnsafeUrl::class);
})->with([
    'loopback' => [['::1']],
    'link local' => [['fe80::1']],
    'unique local' => [['fc00::1']],
    'ipv4 mapped metadata' => [['::ffff:169.254.169.254']],
    'ipv4 compatible metadata' => [['::169.254.169.254']],
    'one good answer and one bad' => [['2606:2800:220:1:248:1893:25c8:1946', '::1']],
]);

it('refuses a hostname that resolves to a cloud metadata address when private hosts are allowed', function (array $addresses): void {
    expect(fn () => ssrfGuardResolving(true, $addresses)->authorize('https://rebind.example.com/token'))
        ->toThrow(UnsafeUrl::class);
})->with([
    'alibaba ipv4 mapped' => [['::ffff:100.100.100.200']],
    'alibaba ipv4 mapped in hextets' => [['::ffff:6464:64c8']],
    'alibaba ipv4 compatible' => [['::100.100.100.200']],
    'alibaba ipv4 compatible in hextets' => [['::6464:64c8']],
    'oracle ipv4 mapped' => [['::ffff:192.0.0.192']],
    'oracle ipv4 mapped in hextets' => [['::ffff:c000:c0']],
    'oracle ipv4 compatible' => [['::192.0.0.192']],
    'oracle ipv4 compatible in hextets' => [['::c000:c0']],
]);
