<?php

declare(strict_types=1);

use Qiick\Outbound\Contracts\MetadataPolicy;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeHostResolver;

/*
 * Standards review S4: a product adds metadata hosts the guard always
 * refuses, also with private hosts allowed; it can never remove one.
 */

/**
 * @param  list<string>  $hosts
 */
function guardWithMetadataPolicy(array $hosts, FakeHostResolver $resolver = new FakeHostResolver): RemoteUrlGuard
{
    return new RemoteUrlGuard($resolver, allowPrivateHosts: true, metadataPolicy: new class($hosts) implements MetadataPolicy
    {
        /** @param  list<string>  $hosts */
        public function __construct(private array $hosts) {}

        public function additionalMetadataHosts(): array
        {
            return $this->hosts;
        }
    });
}

it('refuses a host the policy adds, however it is written, even with private hosts allowed', function (string $policyEntry, string $url): void {
    $result = guardWithMetadataPolicy([$policyEntry], (new FakeHostResolver)->with('meta.example', ['10.9.9.9']))->tryAuthorize($url);

    expect($result['ok'] ? null : $result['reason'])->toBe('Host is not allowed.');
})->with([
    'a name' => ['meta.example', 'https://meta.example/latest'],
    'a name with capitals and a trailing dot' => ['Meta.Example.', 'https://meta.example/latest'],
    'an ipv4 address' => ['10.9.9.9', 'https://10.9.9.9/latest'],
    'an ipv6 address written out in full' => ['FD00:0:0:0:0:0:0:99', 'https://[fd00::99]/latest'],
]);

it('refuses a name that resolves to an address the policy adds', function (): void {
    $result = guardWithMetadataPolicy(['10.9.9.9'], (new FakeHostResolver)->with('app.example', ['10.9.9.9']))->tryAuthorize('https://app.example/token');

    expect($result['ok'] ? null : $result['reason'])->toBe('Host resolves to a disallowed address.');
});

it('keeps the package metadata hosts whatever the policy returns', function (): void {
    $result = guardWithMetadataPolicy([])->tryAuthorize('https://169.254.169.254/latest');

    expect($result['ok'] ? null : $result['reason'])->toBe('Host is not allowed.')
        ->and(guardWithMetadataPolicy(['meta.example'], (new FakeHostResolver)->with('app.example', ['10.1.2.3']))->authorize('https://app.example/token')->pinnedAddress)->toBe('10.1.2.3');
});
