<?php

declare(strict_types=1);

use Qiick\Outbound\Ssrf\PortPolicy;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Ssrf\UrlGuardOptions;

/**
 * Design §5 edge cases (O15–O23 and related) not already covered by the
 * oidc/signaculum pin + IPv6 tables.
 */
it('blocks NAT64, 6to4 and Teredo even when private hosts are allowed', function (string $url): void {
    expect(fn () => ssrfGuardThatMustNotResolve(allowPrivate: true)->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    'nat64' => 'http://[64:ff9b::93.184.216.34]/',
    'nat64 hextets' => 'http://[64:ff9b::5db8:d822]/',
    '6to4-metadata embed' => 'http://[2002:a9fe:a9fe::1]/',
    '6to4 prefix' => 'http://[2002:c000:0202::1]/',
    'teredo' => 'http://[2001:0:53aa:64c::1]/',
    'teredo zero' => 'http://[2001:0000::1]/',
]);

it('blocks link-local, ULA, CGNAT and unspecified when private is off', function (string $url): void {
    expect(fn () => ssrfGuardThatMustNotResolve()->authorize($url))
        ->toThrow(UnsafeUrl::class);
})->with([
    '0.0.0.0' => 'http://0.0.0.0/',
    'cgnat' => 'http://100.64.0.1/',
    'link-local-v4' => 'http://169.254.1.1/',
    'ula' => 'http://[fd12::1]/',
    'link-local-v6' => 'http://[fe80::1]/',
    'unspecified-v6' => 'http://[::]/',
]);

it('canonicalises IDN to punycode before DNS', function (): void {
    $ascii = idn_to_ascii('exämple.test', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    expect($ascii)->toBeString();

    $destination = ssrfGuardResolving(false, ['93.184.216.34'])->authorize('https://exämple.test/');

    expect($destination->host)->toBe(strtolower($ascii));
});

it('rejects empty and whitespace-only URLs with the required reason', function (string $url): void {
    expect(fn () => ssrfGuardResolving()->authorize($url))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL is required.'));
})->with([
    'empty' => '',
    'spaces' => '   ',
]);

it('rejects userinfo, overlong, and non-http schemes with a distinct reason', function (string $url, string $reason): void {
    expect(fn () => ssrfGuardResolving()->authorize($url))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe($reason));
})->with([
    'userinfo' => ['https://u:p@example.test/', 'URL must not contain credentials.'],
    'user-only' => ['https://u@example.test/', 'URL must not contain credentials.'],
    'pass-only' => ['https://:p@example.test/', 'URL must not contain credentials.'],
    'overlong' => ['https://example.test/'.str_repeat('a', 3000), 'URL is too long.'],
    'ftp' => ['ftp://example.test/', 'Only http and https URLs are allowed.'],
    'javascript' => ['javascript:alert(1)', 'URL is not absolute.'],
]);

it('accepts a URL with surrounding whitespace after trim', function (): void {
    expect(ssrfGuardResolving()->authorize('  https://example.test/hook  ')->pinnedAddress)
        ->toBe('93.184.216.34');
});

it('refuses at the exact max length boundary', function (): void {
    $hostAndScheme = 'https://example.test/';
    $ok = $hostAndScheme.str_repeat('a', 2048 - strlen($hostAndScheme));
    $over = $ok.'x';

    expect(strlen($ok))->toBe(2048)
        ->and(ssrfGuardResolving()->authorize($ok)->pinnedAddress)->toBe('93.184.216.34')
        ->and(fn () => ssrfGuardResolving()->authorize($over))->toThrow(UnsafeUrl::class);
});

it('enforces PortPolicy and host allowlist', function (): void {
    $policy = new class implements PortPolicy
    {
        public function allows(int $port, string $scheme): bool
        {
            return $port === 443;
        }
    };

    expect(ssrfGuardResolving()->tryAuthorize('https://example.test/', new UrlGuardOptions(portPolicy: $policy))['ok'])->toBeTrue()
        ->and(ssrfGuardResolving()->tryAuthorize('https://example.test:8443/', new UrlGuardOptions(portPolicy: $policy))['ok'])->toBeFalse()
        ->and(ssrfGuardResolving()->tryAuthorize('https://allowed.test/', new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: ['allowed.test']))['ok'])->toBeTrue()
        ->and(ssrfGuardResolving()->tryAuthorize('https://other.test/', new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: ['allowed.test']))['ok'])->toBeFalse();
});

it('honours httpsOnly for authorize and browser checks', function (): void {
    $guard = ssrfGuardResolving(httpsOnly: true);

    expect($guard->tryAuthorize('http://example.test/')['ok'])->toBeFalse()
        ->and($guard->tryAuthorize('https://example.test/')['ok'])->toBeTrue();

    $guard->assertBrowserUrlIsSafe('https://login.example.com/authorize');

    expect(fn () => $guard->assertBrowserUrlIsSafe('http://login.example.com/authorize'))
        ->toThrow(UnsafeUrl::class)
        ->and(fn () => $guard->assertBrowserUrlIsSafe('javascript:alert(1)'))
        ->toThrow(UnsafeUrl::class);
});

it('checks browser urls for credentials, empty and overlong', function (): void {
    expect(fn () => ssrfGuardResolving()->assertBrowserUrlIsSafe('https://u:p@login.example.com/'))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL must not contain credentials.'))
        ->and(fn () => ssrfGuardResolving()->assertBrowserUrlIsSafe('https://user@login.example.com/'))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL must not contain credentials.'))
        ->and(fn () => ssrfGuardResolving()->assertBrowserUrlIsSafe('https://:secret@login.example.com/'))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL must not contain credentials.'))
        ->and(fn () => ssrfGuardResolving()->assertBrowserUrlIsSafe(''))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL is required.'))
        ->and(fn () => ssrfGuardResolving()->assertBrowserUrlIsSafe('   '))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL is required.'))
        ->and(fn () => ssrfGuardResolving()->assertBrowserUrlIsSafe('https://example.test/'.str_repeat('a', 3000)))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL is too long.'));
});

it('allows http and https browser targets when httpsOnly is off', function (): void {
    $guard = ssrfGuardResolving(httpsOnly: false);

    expect(fn () => $guard->assertBrowserUrlIsSafe('http://login.example.com/authorize'))
        ->not->toThrow(UnsafeUrl::class)
        ->and(fn () => $guard->assertBrowserUrlIsSafe('https://login.example.com/authorize'))
        ->not->toThrow(UnsafeUrl::class);
});

it('lowercases schemes so HTTPS is accepted when httpsOnly is on', function (): void {
    $guard = ssrfGuardResolving(httpsOnly: true);

    expect($guard->tryAuthorize('HTTPS://example.test/')['ok'])->toBeTrue();
    $guard->assertBrowserUrlIsSafe('HTTPS://login.example.com/authorize');
});

it('lowercases allowlist entries so mixed-case config still matches', function (): void {
    $options = new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: ['Example.TEST']);

    expect(ssrfGuardResolving()->tryAuthorize('https://example.test/', $options)['ok'])->toBeTrue()
        ->and(ssrfGuardResolving()->tryAuthorize('https://other.test/', $options)['ok'])->toBeFalse();
});

it('matches an allowlist entry written in Unicode or with a trailing dot, as the URL host is', function (string $allowlistEntry, string $url): void {
    $options = new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: [$allowlistEntry]);

    expect(ssrfGuardResolving()->tryAuthorize($url, $options)['ok'])->toBeTrue()
        ->and(ssrfGuardResolving()->tryAuthorize('https://other.test/', $options)['ok'])->toBeFalse();
})->with([
    'a Unicode name' => ['Bücher.example', 'https://xn--bcher-kva.example/'],
    'a Unicode name against a Unicode URL' => ['Bücher.example', 'https://bücher.example/'],
    'a trailing dot' => ['example.test.', 'https://example.test/'],
    'a trailing dot against a trailing-dot URL' => ['example.test.', 'https://example.test./'],
    'an IPv6 address written out in full' => ['2606:4700:0:0:0:0:0:1111', 'https://[2606:4700::1111]/'],
]);

it('refuses a host with two trailing dots, one more than the root', function (): void {
    expect(ssrfGuardResolving()->tryAuthorize('https://example.test../'))->toBe(['ok' => false, 'reason' => 'URL is invalid.']);
});

it('never lets an allowlist entry that is not a host match anything', function (): void {
    $options = new UrlGuardOptions(enforceHostAllowlist: true, hostAllowlist: ['exa mple.test', '', 'example.test..']);

    expect(ssrfGuardResolving()->tryAuthorize('https://example.test/', $options)['ok'])->toBeFalse();
});

it('exposes tryAuthorize ok/reason keys and destination', function (): void {
    $ok = ssrfGuardResolving()->tryAuthorize('https://example.test/');
    $bad = ssrfGuardResolving()->tryAuthorize('https://127.0.0.1/');

    expect($ok)->toHaveKeys(['ok', 'destination'])
        ->and($ok['ok'])->toBeTrue()
        ->and($bad)->toHaveKeys(['ok', 'reason'])
        ->and($bad['ok'])->toBeFalse()
        ->and($bad)->not->toHaveKey('destination');
});

it('refuses browser urls at the exact max length boundary', function (): void {
    $base = 'https://example.test/';
    $ok = $base.str_repeat('a', 2048 - strlen($base));
    $over = $ok.'x';

    ssrfGuardResolving()->assertBrowserUrlIsSafe($ok);

    expect(fn () => ssrfGuardResolving()->assertBrowserUrlIsSafe($over))
        ->toThrow(fn (UnsafeUrl $e) => expect($e->reason())->toBe('URL is too long.'));
});

it('blocks representative private IPv4 ranges when private is off', function (string $host): void {
    expect(fn () => ssrfGuardThatMustNotResolve()->authorize("http://{$host}/"))
        ->toThrow(UnsafeUrl::class);
})->with([
    '10' => '10.1.2.3',
    '172.16' => '172.16.0.1',
    '192.168' => '192.168.1.1',
    'loopback' => '127.0.0.1',
    'doc' => '192.0.2.1',
    'benchmark' => '198.18.0.1',
    'multicast' => '224.0.0.1',
    'reserved' => '240.0.0.1',
]);
