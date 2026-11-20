<?php

declare(strict_types=1);

use Qiick\Outbound\Ssrf\PortPolicy;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Ssrf\UrlGuardOptions;
use Qiick\Outbound\Testing\FakeHostResolver;

function guard(FakeHostResolver $resolver = new FakeHostResolver, bool $allowPrivate = false, bool $httpsOnly = false): RemoteUrlGuard
{
    return new RemoteUrlGuard($resolver, $allowPrivate, $httpsOnly);
}

it('blocks curl-style IPv4 metadata spellings', function (string $host): void {
    $result = guard()->tryAuthorize("http://{$host}/latest/meta-data/");

    expect($result['ok'])->toBeFalse();
})->with([
    'dotted' => '169.254.169.254',
    'decimal' => '2852039166',
    'octal' => '0251.0376.0251.0376',
    'short' => '169.254.43518',
    'trailing-dot' => '169.254.169.254.',
]);

it('blocks Alibaba and Oracle metadata even when private hosts are allowed', function (string $host): void {
    expect(guard(allowPrivate: true)->tryAuthorize("http://{$host}/")['ok'])->toBeFalse();
})->with([
    'alibaba' => '100.100.100.200',
    'oracle' => '192.0.0.192',
    'ec2-v4' => '169.254.169.254',
    'ec2-v6' => '[fd00:ec2::254]',
    'metadata-name' => 'metadata',
    'gcp-metadata' => 'metadata.google.internal',
]);

it('refuses when any DNS answer is disallowed and when unresolved', function (): void {
    $mixed = new FakeHostResolver(answers: [
        'evil.test' => ['93.184.216.34', '127.0.0.1'],
        'gone.test' => [],
    ]);

    expect(guard($mixed)->tryAuthorize('https://evil.test/')['ok'])->toBeFalse()
        ->and(guard($mixed)->tryAuthorize('https://gone.test/')['ok'])->toBeFalse();
});

it('emits CURLOPT_CONNECT_TO only and prefers an IPv4 pin', function (): void {
    $resolver = new FakeHostResolver(answers: [
        'example.test' => ['2606:4700:4700::1111', '93.184.216.34'],
    ]);

    $destination = guard($resolver)->authorize('https://example.test/path');

    expect($destination->pinnedAddress)->toBe('93.184.216.34')
        ->and($destination->curlConnectToEntry)->toBe('::93.184.216.34:443')
        ->and($destination->guzzleCurlOptions())->toHaveKey('curl')
        ->and($destination->guzzleCurlOptions()['curl'])->toHaveKey(CURLOPT_CONNECT_TO)
        ->and($destination->guzzleCurlOptions()['curl'])->not->toHaveKey(CURLOPT_RESOLVE);
});

it('rejects userinfo', function (): void {
    expect(guard()->tryAuthorize('https://u:p@example.test/')['ok'])->toBeFalse();
});

it('rejects IPv6 zone ids', function (): void {
    expect(guard()->tryAuthorize('http://[fe80::1%eth0]/')['ok'])->toBeFalse()
        ->and(guard()->tryAuthorize('http://[fe80::1%25eth0]/')['ok'])->toBeFalse();
});

it('blocks unspecified addresses', function (string $url): void {
    expect(guard()->tryAuthorize($url)['ok'])->toBeFalse();
})->with([
    'ipv4' => 'http://0.0.0.0/',
    'ipv6-brackets' => 'http://[::]/',
]);

it('blocks NAT64, 6to4 and Teredo even when private hosts are allowed', function (string $url): void {
    expect(guard(allowPrivate: true)->tryAuthorize($url)['ok'])->toBeFalse();
})->with([
    'nat64' => 'http://[64:ff9b::93.184.216.34]/',
    '6to4-metadata' => 'http://[2002:a9fe:a9fe::1]/',
    'teredo' => 'http://[2001:0:53aa:64c::1]/',
]);

it('blocks link-local, ULA and CGNAT when private hosts are off', function (string $url): void {
    expect(guard()->tryAuthorize($url)['ok'])->toBeFalse();
})->with([
    'link-local-v4' => 'http://169.254.1.1/',
    'cgnat' => 'http://100.64.0.1/',
    'ula' => 'http://[fd12::1]/',
    'link-local-v6' => 'http://[fe80::1]/',
]);

it('blocks localhost and localhost. after trailing-dot normalisation', function (string $url): void {
    expect(guard()->tryAuthorize($url)['ok'])->toBeFalse();
})->with([
    'localhost' => 'http://localhost/',
    'trailing-dot' => 'http://localhost./',
    'localdomain' => 'http://localhost.localdomain/',
    'dot-localhost' => 'http://app.localhost/',
    'dot-local' => 'http://printer.local/',
    'dot-internal' => 'http://api.internal/',
]);

it('refuses empty, overlong and non-http schemes', function (): void {
    $overlong = 'https://example.test/'.str_repeat('a', 3000);

    expect(guard()->tryAuthorize('')['ok'])->toBeFalse()
        ->and(guard()->tryAuthorize($overlong)['ok'])->toBeFalse()
        ->and(guard()->tryAuthorize('ftp://example.test/')['ok'])->toBeFalse()
        ->and(guard()->tryAuthorize('not-a-url')['ok'])->toBeFalse();
});

it('blocks representative private and reserved IPv4 ranges when private is off', function (string $host): void {
    expect(guard()->tryAuthorize("http://{$host}/")['ok'])->toBeFalse();
})->with([
    '10' => '10.1.2.3',
    '172.16' => '172.16.0.1',
    '192.168' => '192.168.1.1',
    'loopback' => '127.0.0.1',
    'doc' => '192.0.2.1',
    'benchmark' => '198.18.0.1',
    'multicast' => '224.0.0.1',
]);

it('canonicalises IDN to punycode before allowlist and DNS', function (): void {
    $unicode = 'https://exämple.test/';
    $asciiHost = idn_to_ascii('exämple.test', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    expect($asciiHost)->toBeString();

    $resolver = new FakeHostResolver(answers: [
        strtolower($asciiHost) => ['93.184.216.34'],
    ]);

    $destination = guard($resolver)->authorize($unicode);

    expect($destination->host)->toBe(strtolower($asciiHost))
        ->and($destination->pinnedAddress)->toBe('93.184.216.34');
});

it('enforces an optional host allowlist', function (): void {
    $resolver = new FakeHostResolver(answers: [
        'allowed.test' => ['93.184.216.34'],
        'other.test' => ['93.184.216.34'],
    ]);
    $options = new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: ['allowed.test']);

    expect(guard($resolver)->tryAuthorize('https://allowed.test/', $options)['ok'])->toBeTrue()
        ->and(guard($resolver)->tryAuthorize('https://other.test/', $options)['ok'])->toBeFalse();
});

it('applies an optional PortPolicy', function (): void {
    $resolver = new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]);
    $policy = new class implements PortPolicy
    {
        public function allows(int $port, string $scheme): bool
        {
            return $port === 443;
        }
    };
    $options = new UrlGuardOptions(portPolicy: $policy);

    expect(guard($resolver)->tryAuthorize('https://example.test/', $options)['ok'])->toBeTrue()
        ->and(guard($resolver)->tryAuthorize('https://example.test:8443/', $options)['ok'])->toBeFalse();
});

it('honours httpsOnly on authorize and assertBrowserUrlIsSafe', function (): void {
    $resolver = new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]);
    $httpsGuard = guard($resolver, httpsOnly: true);

    expect($httpsGuard->tryAuthorize('http://example.test/')['ok'])->toBeFalse()
        ->and($httpsGuard->tryAuthorize('https://example.test/')['ok'])->toBeTrue();

    expect(fn () => $httpsGuard->assertBrowserUrlIsSafe('http://example.test/'))
        ->toThrow(UnsafeUrl::class)
        ->and(fn () => $httpsGuard->assertBrowserUrlIsSafe('javascript:alert(1)'))
        ->toThrow(UnsafeUrl::class);

    $httpsGuard->assertBrowserUrlIsSafe('https://example.test/');
});

it('allows private addresses only when allowPrivateHosts is on, still blocking metadata', function (): void {
    expect(guard()->tryAuthorize('http://10.0.0.5/')['ok'])->toBeFalse()
        ->and(guard(allowPrivate: true)->tryAuthorize('http://10.0.0.5/')['ok'])->toBeTrue()
        ->and(guard(allowPrivate: true)->tryAuthorize('http://169.254.169.254/')['ok'])->toBeFalse();
});

it('pins a literal IP without DNS and brackets IPv6 CONNECT_TO entries', function (): void {
    $destination = guard()->authorize('https://[2606:4700:4700::1111]/');

    expect($destination->pinnedAddress)->toBe('2606:4700:4700::1111')
        ->and($destination->curlConnectToEntry)->toBe('::[2606:4700:4700::1111]:443')
        ->and($destination->port)->toBe(443);
});
