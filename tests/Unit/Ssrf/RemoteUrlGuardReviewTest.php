<?php

declare(strict_types=1);

use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Ssrf\DnsHostResolver;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Ssrf\UrlGuardOptions;
use Qiick\Outbound\Testing\FakeHostResolver;

/**
 * Adversarial-review fixes for #2: IDN bypass, expanded metadata, per-call
 * options, DnsHostResolver seam, curl options shape, IPv4/IPv6 mutants.
 */
it('refuses full-width IPv6 metadata that IDN would otherwise mint into a pin', function (): void {
    // Full-width digits + full-width colon (U+FF1A): under IDNA_DEFAULT this
    // became fd00:ec2:0::254 and bypassed the text metadata list.
    $host = "\u{ff46}\u{ff44}\u{ff10}\u{ff10}\u{ff1a}\u{ff45}\u{ff43}\u{ff12}\u{ff1a}\u{ff10}\u{ff1a}\u{ff1a}\u{ff12}\u{ff15}\u{ff14}";

    expect(fn () => ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize("http://{$host}/"))
        ->toThrow(UnsafeUrl::class);
});

it('refuses full-width and mixed ASCII/full-width IPv4 metadata spellings', function (string $host): void {
    expect(fn () => ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize("http://{$host}/"))
        ->toThrow(UnsafeUrl::class);
})->with([
    'full-width dotted metadata' => "\u{ff11}\u{ff16}\u{ff19}.\u{ff12}\u{ff15}\u{ff14}.\u{ff11}\u{ff16}\u{ff19}.\u{ff12}\u{ff15}\u{ff14}",
    'mixed full-width last octet' => "169.254.169.\u{ff12}\u{ff15}\u{ff14}",
]);

it('refuses full-width private IPv4 with private hosts on (kills BooleanOr on IDN→IP refuse)', function (): void {
    // １０.０.０.１ → 10.0.0.1 via IDN; must not be pinned even when private is allowed.
    $host = "\u{ff11}\u{ff10}.\u{ff10}.\u{ff10}.\u{ff11}";

    expect(fn () => ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize("http://{$host}/"))
        ->toThrow(UnsafeUrl::class);
});

it('refuses IDN output that is IP-like via colon or a public IPv6 (kills BooleanOrToBooleanAnd)', function (string $host): void {
    // A&&B||C would accept "a:b.example" (strpbrk only); (A||B)&&C would accept a
    // public IPv6 string and pin it. Both must stay refused.
    expect(fn () => ssrfGuardResolving()->authorize("https://{$host}/"))
        ->toThrow(UnsafeUrl::class);
})->with([
    'full-width colon in a label' => "a\u{ff1a}b.example",
    'full-width public IPv6' => "\u{ff12}\u{ff16}\u{ff10}\u{ff16}\u{ff1a}\u{ff14}\u{ff17}\u{ff10}\u{ff10}\u{ff1a}\u{ff1a}\u{ff11}",
]);

it('strips an ideographic full stop after IDN and still blocks localhost', function (): void {
    // U+FF61 survives parse_url (U+3002 is mangled by PHP's parser on some builds)
    // and IDN-maps to a trailing ASCII dot that normalizeHost must strip.
    expect(fn () => ssrfGuardResolving()->authorize("http://localhost\u{ff61}/"))
        ->toThrow(UnsafeUrl::class);
});

it('takes the guard private-host setting as a ceiling a call can only narrow', function (): void {
    $closed = new RemoteUrlGuard(new FakeHostResolver, allowPrivateHosts: false, httpsOnly: false);
    $open = new RemoteUrlGuard(new FakeHostResolver, allowPrivateHosts: true, httpsOnly: false);

    expect($closed->tryAuthorize('http://10.0.0.5/')['ok'])->toBeFalse()
        ->and($closed->tryAuthorize('http://10.0.0.5/', new UrlGuardOptions(allowPrivateHosts: true))['ok'])->toBeFalse()
        ->and($open->tryAuthorize('http://10.0.0.5/')['ok'])->toBeTrue()
        ->and($open->tryAuthorize('http://10.0.0.5/', new UrlGuardOptions(allowPrivateHosts: true))['ok'])->toBeTrue()
        ->and($open->tryAuthorize('http://10.0.0.5/', new UrlGuardOptions(allowPrivateHosts: false))['ok'])->toBeFalse();

    $public = new RemoteUrlGuard(
        new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]),
        allowPrivateHosts: false,
        httpsOnly: false,
    );

    expect($public->tryAuthorize('http://example.test/', new UrlGuardOptions(httpsOnly: true))['ok'])->toBeFalse()
        ->and($public->tryAuthorize('https://example.test/', new UrlGuardOptions(httpsOnly: true))['ok'])->toBeTrue()
        ->and($public->tryAuthorize('http://example.test/')['ok'])->toBeTrue();
});

it('blocks the expanded always-on cloud metadata addresses with private on', function (string $url): void {
    expect(fn () => ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'ecs' => 'http://169.254.170.2/',
    'eks-v4' => 'http://169.254.170.23/',
    'eks-v6' => 'http://[fd00:ec2::23]/',
    'gcp-v6' => 'http://[fd20:ce::254]/',
    'azure-wireserver' => 'http://168.63.129.16/',
]);

it('blocks site-local when private is off', function (): void {
    expect(fn () => ssrfGuardThatMustNotResolve()->authorize('http://[fec0::1]/'))
        ->toThrow(UnsafeUrl::class);
});

it('blocks local-use NAT64 even with private hosts on', function (): void {
    expect(fn () => ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize('http://[64:ff9b:1::93.184.216.34]/'))
        ->toThrow(UnsafeUrl::class);
});

it('DnsHostResolver uses the DNS seam and never needs the network', function (): void {
    $resolver = new DnsHostResolver(
        dnsGetRecord: fn (string $host, int $type): array => [
            ['ip' => '93.184.216.34'],
            ['ipv6' => '2606:2800:220:1:248:1893:25c8:1946'],
        ],
        getHostByNameL: fn (string $host): never => throw new RuntimeException('fallback must not run'),
    );

    expect($resolver)->toBeInstanceOf(HostResolver::class)
        ->and($resolver->resolve('example.test'))->toBe([
            '93.184.216.34',
            '2606:2800:220:1:248:1893:25c8:1946',
        ]);

    $empty = new DnsHostResolver(
        dnsGetRecord: fn (): array => [],
        getHostByNameL: fn (): array => [],
    );

    expect($empty->resolve('qiick-outbound-does-not-exist.invalid'))->toBe([]);
});

it('guzzleCurlOptions disables redirects and uses CONNECT_TO only', function (): void {
    $destination = ssrfGuardThatMustNotResolve()->authorize('https://93.184.216.34/');

    expect($destination->guzzleCurlOptions())->toMatchArray([
        'allow_redirects' => false,
        'curl' => [CURLOPT_CONNECT_TO => ['::93.184.216.34:443']],
    ]);
});

it('refuses max uint32 curl-IPv4 spellings as non-dotted (kills GreaterToGreaterOrEqual on 0xFFFFFFFF)', function (string $url): void {
    // 0xffffffff / 017777777777 read as 255.255.255.255 then fail the
    // "spelling must equal dotted decimal" check. If the 0xFFFFFFFF bound
    // becomes >=, they become DNS names and the resolving guard would pin.
    expect(fn () => ssrfGuardResolving()->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'hex max uint32' => 'https://0xffffffff/token',
    'octal max uint32 (11 significant digits)' => 'https://017777777777/token',
]);

it('refuses a last part exactly at the remaining-bytes ceiling as a name path', function (): void {
    // 1.16777216 → last >= 256^3; stays a name. Boundary-1 is curl-IPv4.
    expect(ssrfGuardResolving()->authorize('https://1.16777216/token')->pinnedAddress)
        ->toBe('93.184.216.34')
        ->and(fn () => ssrfGuardResolving()->authorize('https://1.16777215/token'))
        ->toThrow(UnsafeUrl::class);
});

it('strips a trailing DNS dot before resolve (FakeHostResolver key)', function (): void {
    $guard = new RemoteUrlGuard(
        new FakeHostResolver(answers: ['example.com' => ['93.184.216.34']]),
    );

    expect($guard->authorize('https://example.com./token')->host)->toBe('example.com')
        ->and($guard->authorize('https://example.com./token')->pinnedAddress)->toBe('93.184.216.34');
});

it('refuses a lone bracket as a broken IPv6 literal, not a DNS name', function (string $url): void {
    expect(fn () => ssrfGuardThatMustNotResolve()->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'closing only' => 'http://foo]/',
    'opening only' => 'http://[foo/',
]);

it('accepts underscore hosts that curl and S3 use', function (): void {
    $guard = new RemoteUrlGuard(
        new FakeHostResolver(answers: [
            'foo_bar.example' => ['93.184.216.34'],
            'my_bucket.s3.amazonaws.com' => ['93.184.216.34'],
        ]),
    );

    expect($guard->authorize('https://foo_bar.example/')->pinnedAddress)->toBe('93.184.216.34')
        ->and($guard->authorize('https://my_bucket.s3.amazonaws.com/')->host)->toBe('my_bucket.s3.amazonaws.com');
});

it('pins a dotted-decimal address with a 255 octet when private is on', function (): void {
    // Kills GreaterToGreaterOrEqual / DecrementInteger on `> 255` (255 must stay legal).
    expect(ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize('https://255.0.0.1/')->pinnedAddress)
        ->toBe('255.0.0.1')
        ->and(ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize('https://10.0.0.1/')->pinnedAddress)
        ->toBe('10.0.0.1');
});

it('pins a public IPv4 with odd octets (kills bitshift DecrementInteger in sprintf)', function (): void {
    expect(ssrfGuardThatMustNotResolve()->authorize('https://93.185.217.35/')->pinnedAddress)
        ->toBe('93.185.217.35');
});

it('pins a public IPv6 whose low 32 bits look private (kills addressForms early-return removal)', function (): void {
    // 2606:4700::a00:5 embeds 10.0.0.5 in the low 32 bits but is not v4-mapped/compatible.
    expect(ssrfGuardThatMustNotResolve()->authorize('https://[2606:4700::a00:5]/')->pinnedAddress)
        ->toBe('2606:4700::a00:5');
});

it('lowercases IDN output so mixed-case punycode still matches the allowlist', function (): void {
    $guard = new RemoteUrlGuard(
        new FakeHostResolver(answers: ['xn--exmple-cua.test' => ['93.184.216.34']]),
    );

    expect($guard->authorize(
        'https://exämple.test/',
        new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: ['xn--exmple-cua.test']),
    )->host)->toBe('xn--exmple-cua.test');
});

it('lowercases the raw host so mixed-case URLs match a lowercase allowlist', function (): void {
    $guard = new RemoteUrlGuard(
        new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']]),
    );

    expect($guard->authorize(
        'https://Example.TEST/',
        new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: ['example.test']),
    )->host)->toBe('example.test');
});

it('strips a trailing dot produced by IDN mapping of an ideographic full stop', function (): void {
    $guard = new RemoteUrlGuard(
        new FakeHostResolver(answers: ['example.com' => ['93.184.216.34']]),
    );

    // U+FF61 → '.' ; trailing one must be stripped after IDN or FakeHostResolver misses.
    expect($guard->authorize("https://example.com\u{ff61}/")->pinnedAddress)->toBe('93.184.216.34');
});

it('treats a five-part numeric host that would confuse a raised part ceiling as a name', function (): void {
    // IncrementInteger on `count > 4` would feed 1.2.3.4.0 into the IPv4 reader.
    expect(ssrfGuardResolving()->authorize('https://1.2.3.4.0/token')->pinnedAddress)
        ->toBe('93.184.216.34');
});

it('still refuses five-part numeric hosts as names after the part-count ceiling', function (): void {
    // IncrementInteger on `> 4` would accept 1.2.3.4.5 into the IPv4 reader.
    expect(ssrfGuardResolving()->authorize('https://1.2.3.4.5/token')->pinnedAddress)
        ->toBe('93.184.216.34');
});
