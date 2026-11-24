<?php

declare(strict_types=1);

use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Testing\FakeHostResolver;

/**
 * Curl-style IPv4 + CONNECT_TO pin surface. Ported from
 * qiick-io/laravel-oidc RemoteUrlGuardPinTest (signaculum-core#189).
 */
dataset('private host setting', [
    'setting off' => false,
    'setting on' => true,
]);

dataset('metadata ipv4 spellings', [
    'decimal' => 'https://2852039166/latest/meta-data/',
    'decimal with a port' => 'https://2852039166:443/latest/meta-data/',
    'dotted octal' => 'https://0251.0376.0251.0376/latest/meta-data/',
    'dotted hex' => 'https://0xa9.0xfe.0xa9.0xfe/latest/meta-data/',
    'dotted hex, upper case' => 'https://0XA9.0XFE.0XA9.0XFE/latest/meta-data/',
    'one hex number' => 'https://0xa9fea9fe/latest/meta-data/',
    'one octal number' => 'https://025177524776/latest/meta-data/',
    'mixed bases' => 'https://169.0376.0xa9.254/latest/meta-data/',
    'three parts' => 'https://169.254.43518/latest/meta-data/',
    'two parts' => 'https://169.16689662/latest/meta-data/',
    'trailing dot' => 'https://169.254.169.254./latest/meta-data/',
    'decimal with a trailing dot' => 'https://2852039166./latest/meta-data/',
]);

dataset('cloud metadata ipv4 spellings', [
    'alibaba dotted decimal' => 'https://100.100.100.200/latest/meta-data/',
    'alibaba dotted decimal with a port' => 'https://100.100.100.200:443/latest/meta-data/',
    'alibaba decimal' => 'https://1684301000/latest/meta-data/',
    'alibaba dotted octal' => 'https://0144.0144.0144.0310/latest/meta-data/',
    'alibaba dotted hex' => 'https://0x64.0x64.0x64.0xc8/latest/meta-data/',
    'alibaba dotted hex, upper case' => 'https://0X64.0X64.0X64.0XC8/latest/meta-data/',
    'alibaba one hex number' => 'https://0x646464c8/latest/meta-data/',
    'alibaba one octal number' => 'https://014431062310/latest/meta-data/',
    'alibaba mixed bases' => 'https://100.0144.0x64.200/latest/meta-data/',
    'alibaba three parts' => 'https://100.100.25800/latest/meta-data/',
    'alibaba two parts' => 'https://100.6579400/latest/meta-data/',
    'alibaba trailing dot' => 'https://100.100.100.200./latest/meta-data/',
    'oracle dotted decimal' => 'https://192.0.0.192/latest/meta-data/',
    'oracle dotted decimal with a port' => 'https://192.0.0.192:443/latest/meta-data/',
    'oracle decimal' => 'https://3221225664/latest/meta-data/',
    'oracle dotted octal' => 'https://0300.0.0.0300/latest/meta-data/',
    'oracle one octal number' => 'https://030000000300/latest/meta-data/',
    'oracle dotted hex' => 'https://0xc0.0x0.0x0.0xc0/latest/meta-data/',
    'oracle dotted hex, upper case' => 'https://0XC0.0X0.0X0.0XC0/latest/meta-data/',
    'oracle one hex number' => 'https://0xc00000c0/latest/meta-data/',
    'oracle mixed bases' => 'https://0300.0x0.0.192/latest/meta-data/',
    'oracle three parts' => 'https://192.0.192/latest/meta-data/',
    'oracle two parts' => 'https://192.192/latest/meta-data/',
    'oracle trailing dot' => 'https://192.0.0.192./latest/meta-data/',
]);

it('refuses every spelling of the ipv4 metadata address, whatever the setting', function (string $url, bool $allowPrivate): void {
    expect(fn () => ssrfGuardResolving($allowPrivate)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with('metadata ipv4 spellings')->with('private host setting');

it('reads an ipv4 spelling as the address it is, and never looks it up as a name', function (string $url, bool $allowPrivate): void {
    expect(fn () => ssrfGuardThatMustNotResolve($allowPrivate)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with('metadata ipv4 spellings')->with('private host setting');

it('refuses every spelling of the alibaba and oracle metadata addresses, whatever the setting', function (string $url, bool $allowPrivate): void {
    expect(fn () => ssrfGuardResolving($allowPrivate)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with('cloud metadata ipv4 spellings')->with('private host setting');

it('reads an alibaba or oracle metadata spelling as the address it is, and never looks it up as a name', function (string $url, bool $allowPrivate): void {
    expect(fn () => ssrfGuardThatMustNotResolve($allowPrivate)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with('cloud metadata ipv4 spellings')->with('private host setting');

it('refuses an ipv4 address that is not written in dotted decimal, whatever the setting', function (string $url, bool $allowPrivate): void {
    expect(fn () => ssrfGuardThatMustNotResolve($allowPrivate)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'short form, link local (169.254.255.254)' => 'https://169.254.65534/token',
    'short form, public (1.0.0.1)' => 'https://1.1/token',
    'decimal loopback' => 'https://2130706433/token',
    'hex loopback, two parts' => 'https://0x7f.1/token',
    'short loopback' => 'https://127.1/token',
    'octal private' => 'https://012.0.0.1/token',
    'leading zeroes' => 'https://127.000.000.001/token',
    'decimal public (93.184.216.34)' => 'https://1572395042/token',
    'canonical public with a trailing dot' => 'https://93.184.216.34./token',
])->with('private host setting');

it('still treats a name that only looks numeric as a name', function (string $url): void {
    expect(ssrfGuardResolving()->authorize($url)->pinnedAddress)->toBe('93.184.216.34');
})->with([
    'five parts' => 'https://1.2.3.4.5/token',
    'a part over 255' => 'https://256.1.1.1/token',
    'a whole address over 32 bits' => 'https://4294967296/token',
    'a last part too big for the bytes left' => 'https://1.16777216/token',
    'twelve significant digits' => 'https://123456789012/token',
    'hex digits without the prefix' => 'https://cafe.1.2.3/token',
    'a hostname made of hex letters' => 'https://deadbeef.example.com/token',
]);

it('refuses a host idn_to_ascii cannot canonicalise (empty DNS label)', function (): void {
    // Design: reject when IDN conversion fails. `1..2` is not curl-IPv4 either.
    expect(fn () => ssrfGuardResolving()->authorize('https://1..2/token'))
        ->toThrow(UnsafeUrl::class);
});

it('refuses a hostname that resolves to the metadata address, whatever the setting', function (array $addresses, bool $allowPrivate): void {
    expect(fn () => ssrfGuardResolving($allowPrivate, $addresses)->authorize('https://metadata.attacker.example/latest/meta-data/'))
        ->toThrow(UnsafeUrl::class);
})->with([
    'ec2 ipv4' => [['169.254.169.254']],
    'ec2 ipv6' => [['fd00:ec2::254']],
    'ec2 ipv6, uncompressed' => [['FD00:EC2:0:0:0:0:0:254']],
    'ipv4 mapped' => [['::ffff:169.254.169.254']],
    'ipv4 compatible' => [['::169.254.169.254']],
    'behind a public answer' => [['93.184.216.34', '169.254.169.254']],
])->with('private host setting');

it('refuses a hostname that resolves to an alibaba or oracle metadata address, whatever the setting', function (array $addresses, bool $allowPrivate): void {
    expect(fn () => ssrfGuardResolving($allowPrivate, $addresses)->authorize('https://metadata.attacker.example/latest/meta-data/'))
        ->toThrow(UnsafeUrl::class);
})->with([
    'alibaba ipv4' => [['100.100.100.200']],
    'alibaba ipv4 mapped' => [['::ffff:100.100.100.200']],
    'alibaba ipv4 mapped in hextets' => [['::ffff:6464:64c8']],
    'alibaba ipv4 compatible' => [['::100.100.100.200']],
    'alibaba ipv4 compatible in hextets' => [['::6464:64c8']],
    'alibaba behind a public answer' => [['93.184.216.34', '100.100.100.200']],
    'oracle ipv4' => [['192.0.0.192']],
    'oracle ipv4 mapped' => [['::ffff:192.0.0.192']],
    'oracle ipv4 mapped in hextets' => [['::ffff:c000:c0']],
    'oracle ipv4 compatible' => [['::192.0.0.192']],
    'oracle ipv4 compatible in hextets' => [['::c000:c0']],
    'oracle behind a public answer' => [['93.184.216.34', '192.0.0.192']],
])->with('private host setting');

it('keeps the metadata names blocked with a trailing dot', function (string $url, bool $allowPrivate): void {
    expect(fn () => ssrfGuardResolving($allowPrivate)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'gce metadata name' => 'https://metadata.google.internal./computeMetadata/v1/',
    'bare metadata name' => 'https://metadata./computeMetadata/v1/',
])->with('private host setting');

it('keeps the blocked names blocked with a trailing dot', function (string $url): void {
    expect(fn () => ssrfGuardResolving()->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'localhost' => 'https://localhost./token',
    'an internal name' => 'https://redis.internal./token',
    'an mdns name' => 'https://printer.local./token',
]);

it('blocks every blocked hostname form without relying on DNS failure', function (string $url): void {
    // Resolver would authorise: refusal must come from isBlockedHostName.
    expect(fn () => ssrfGuardResolving()->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'localhost' => 'https://localhost/token',
    'localhost.localdomain' => 'https://localhost.localdomain/token',
    'dot-localhost' => 'https://app.localhost/token',
    'dot-local' => 'https://printer.local/token',
    'dot-internal' => 'https://api.internal/token',
]);

it('pins every destination it authorises to exactly one checked address', function (string $url, array $addresses, bool $allowPrivate, string $pinnedAddress): void {
    $destination = ssrfGuardResolving($allowPrivate, $addresses)->authorize($url);
    $formatted = str_contains($pinnedAddress, ':') ? '['.$pinnedAddress.']' : $pinnedAddress;

    $options = $destination->guzzleCurlOptions();

    expect($destination->pinnedAddress)->toBe($pinnedAddress)
        ->and($destination->curlConnectToEntry)->toBe(sprintf('::%s:%d', $formatted, $destination->port))
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['curl'])->toHaveKey(CURLOPT_CONNECT_TO)
        ->and($options['curl'])->not->toHaveKey(CURLOPT_RESOLVE);
})->with([
    'a public hostname' => ['https://idp.example.com/token', ['93.184.216.34'], false, '93.184.216.34'],
    'a hostname with a port' => ['https://idp.example.com:8443/token', ['93.184.216.34'], false, '93.184.216.34'],
    'a fully qualified hostname' => ['https://idp.example.com./token', ['93.184.216.34'], false, '93.184.216.34'],
    'a hostname with only ipv6' => ['https://v6.example.com/token', ['2606:2800:220:1:248:1893:25c8:1946'], false, '2606:2800:220:1:248:1893:25c8:1946'],
    'a public ipv4 literal' => ['https://93.184.216.34/token', [], false, '93.184.216.34'],
    'a public ipv6 literal' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/token', [], false, '2606:2800:220:1:248:1893:25c8:1946'],
    'a public ipv4 mapped literal' => ['https://[::ffff:93.184.216.34]/token', [], false, '::ffff:93.184.216.34'],
    'a private literal, setting on' => ['http://10.0.0.5:8080/token', [], true, '10.0.0.5'],
    'a private ipv6 literal, setting on' => ['http://[fc00::1]:8443/token', [], true, 'fc00::1'],
    'a hostname answering privately, setting on' => ['http://idp.lan.example/token', ['10.0.0.5'], true, '10.0.0.5'],
    'localhost, setting on' => ['http://localhost:9000/token', ['127.0.0.1'], true, '127.0.0.1'],
]);

it('refuses a host that is nothing but the root dot', function (): void {
    expect(fn () => ssrfGuardThatMustNotResolve()->authorize('https://./token'))
        ->toThrow(UnsafeUrl::class);
});

it('refuses a host that does not resolve, whatever the setting, because there is nothing to pin', function (bool $allowPrivate): void {
    expect(fn () => ssrfGuardResolving($allowPrivate, [])->authorize('https://nxdomain.example.com/token'))
        ->toThrow(UnsafeUrl::class);
})->with('private host setting');

it('never pins a resolver answer that is not an address', function (bool $allowPrivate): void {
    expect(fn () => ssrfGuardResolving($allowPrivate, ['metadata.google.internal'])->authorize('https://cname.example.com/token'))
        ->toThrow(UnsafeUrl::class)
        ->and(ssrfGuardResolving($allowPrivate, ['metadata.google.internal', '93.184.216.34'])->authorize('https://cname.example.com/token')->pinnedAddress)
        ->toBe('93.184.216.34');
})->with('private host setting');

it('checks every record of a hostname, and pins one of them, ipv4 first', function (): void {
    $addresses = ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34', '93.184.216.35'];

    expect(ssrfGuardResolving(false, $addresses)->authorize('https://many.example.com/token')->pinnedAddress)
        ->toBe('93.184.216.34')
        ->and(fn () => ssrfGuardResolving(false, [...$addresses, '10.0.0.5'])->authorize('https://many.example.com/token'))
        ->toThrow(UnsafeUrl::class);
});

it('pins default scheme ports as :443 and :80 in CONNECT_TO', function (): void {
    $https = ssrfGuardResolving()->authorize('https://idp.example.com/token');
    $http = ssrfGuardResolving(allowPrivate: true)->authorize('http://idp.example.com/token');

    expect($https->port)->toBe(443)
        ->and($https->curlConnectToEntry)->toBe('::93.184.216.34:443')
        ->and($http->port)->toBe(80)
        ->and($http->curlConnectToEntry)->toBe('::93.184.216.34:80');
});

it('resolves once per authorize and returns CONNECT_TO for the checked address', function (): void {
    // One-element array as a mutable box (PHP cannot capture int by ref in ctor).
    $box = [0];
    $resolver = new class($box) implements HostResolver
    {
        /** @param  array{0: int}  $box */
        public function __construct(private array &$box) {}

        public function resolve(string $host): array
        {
            $this->box[0]++;

            return $this->box[0] === 1 ? ['93.184.216.34'] : ['169.254.169.254'];
        }
    };
    $guard = new RemoteUrlGuard($resolver);

    $first = $guard->authorize('https://rebind.example.com/token');

    expect($first->curlConnectToEntry)->toBe('::93.184.216.34:443')
        ->and($box[0])->toBe(1)
        ->and(fn () => $guard->authorize('https://rebind.example.com/token'))
        ->toThrow(UnsafeUrl::class)
        ->and($box[0])->toBe(2);
});

/*
 * The reason of each refusal, not only the refusal: a metadata address in any
 * spelling is a refused host, any other non-dotted spelling an invalid URL.
 * Getting the address wrong (a base, the bytes left for the last part) turns
 * one into the other, so these pin how the guard reads what curl reads.
 */

function guardRefusalReason(RemoteUrlGuard $guard, string $url): ?string
{
    $result = $guard->tryAuthorize($url);

    return $result['ok'] ? null : $result['reason'];
}

it('refuses every spelling of a metadata address as a refused host', function (string $url, bool $allowPrivate): void {
    expect(guardRefusalReason(ssrfGuardThatMustNotResolve($allowPrivate), $url))->toBe('Host is not allowed.');
})->with('metadata ipv4 spellings')->with('private host setting');

it('refuses every spelling of the alibaba and oracle metadata addresses as a refused host', function (string $url, bool $allowPrivate): void {
    expect(guardRefusalReason(ssrfGuardThatMustNotResolve($allowPrivate), $url))->toBe('Host is not allowed.');
})->with('cloud metadata ipv4 spellings')->with('private host setting');

it('refuses any other non-dotted spelling as an invalid URL', function (string $url, bool $allowPrivate): void {
    expect(guardRefusalReason(ssrfGuardThatMustNotResolve($allowPrivate), $url))->toBe('URL is invalid.');
})->with([
    'short form' => 'https://169.254.65534/token',
    'hex, two parts' => 'https://0x7f.1/token',
    'octal' => 'https://012.0.0.1/token',
    'one octal number' => 'https://017700000001/token',
    'a padded octal zero' => 'https://127.00.0.1/token',
    'trailing dot' => 'https://93.184.216.34./token',
    'a leading part of 255' => 'https://0xff.1/token',
])->with('private host setting');

it('refuses a host that is not dot-separated letters, digits, hyphens and underscores', function (string $url): void {
    expect(guardRefusalReason(ssrfGuardResolving(), $url))->toBe('URL is invalid.');
})->with([
    'a full-width colon, mapped to a colon' => 'https://a：b/token',
    'a closing bracket' => 'https://a]/token',
    'an opening bracket' => 'https://a[b/token',
    'an unterminated ipv6 literal' => 'https://[::1/token',
    'a symbol' => 'https://exa!mple.com/token',
    'an empty label' => 'https://a..b/token',
]);

it('refuses each private and reserved network unless private hosts are allowed', function (string $address): void {
    expect(guardRefusalReason(ssrfGuardResolving(), "https://{$address}/token"))->toBe('Host is not allowed.')
        ->and(guardRefusalReason(ssrfGuardResolving(allowPrivate: true), "https://{$address}/token"))->toBeNull();
})->with([
    '0.0.0.0/8' => '0.1.2.3',
    '10.0.0.0/8' => '10.1.2.3',
    '100.64.0.0/10' => '100.64.1.2',
    '127.0.0.0/8' => '127.1.2.3',
    '169.254.0.0/16' => '169.254.1.2',
    '172.16.0.0/12' => '172.16.1.2',
    '192.0.0.0/24' => '192.0.0.8',
    '192.0.2.0/24' => '192.0.2.8',
    '192.168.0.0/16' => '192.168.1.2',
    '198.18.0.0/15' => '198.18.1.2',
    '198.51.100.0/24' => '198.51.100.8',
    '203.0.113.0/24' => '203.0.113.8',
    '224.0.0.0/4' => '224.1.2.3',
    '240.0.0.0/4' => '240.1.2.3',
    ':: (compatible 0.0.0.0)' => '[::]',
    '::1 (compatible 0.0.0.1)' => '[::1]',
    'fc00::/7' => '[fc00::8]',
    'fe80::/10' => '[fe80::8]',
    'fec0::/10' => '[fec0::8]',
    'ff00::/8' => '[ff02::8]',
    '2001:db8::/32' => '[2001:db8::8]',
    'ipv4 mapped private' => '[::ffff:10.1.2.3]',
]);

it('pins only the resolver answers that are ip addresses, in canonical form', function (): void {
    $guard = new RemoteUrlGuard((new FakeHostResolver)->with('app.example', [123, 'not-an-ip', '2001:DB8:0:0:0:0:0:1', '93.184.216.34']), allowPrivateHosts: true);

    expect($guard->authorize('https://app.example/token')->pinnedAddress)->toBe('93.184.216.34');
});
