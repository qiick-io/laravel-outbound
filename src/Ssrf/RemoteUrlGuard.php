<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

use Qiick\Outbound\Contracts\HostResolver;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Canonical SSRF guard for every server-side fetch of a user- or config-supplied
 * URL. Superset of signaculum WebhookUrlGuardService and oidc RemoteUrlGuardService.
 *
 * Normative evaluation order is in the package design (2026-11-20). Every
 * authorised destination returns exactly one pin for CURLOPT_CONNECT_TO.
 *
 * @api
 */
final class RemoteUrlGuard
{
    private const int MaxUrlLength = 2048;

    /**
     * Blocked when private hosts are not allowed.
     *
     * @var list<string>
     */
    private const array BlockedNetworks = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
        '2001:db8::/32',
    ];

    /**
     * Always blocked, also with allowPrivateHosts: EC2/GCP, Alibaba, Oracle.
     *
     * @var list<string>
     */
    private const array MetadataHosts = [
        'metadata',
        'metadata.google.internal',
        '169.254.169.254',
        'fd00:ec2::254',
        '100.100.100.200',
        '192.0.0.192',
    ];

    /**
     * Transition prefixes always blocked (even when private hosts are allowed).
     *
     * @var list<string>
     */
    private const array AlwaysBlockedTransitionNetworks = [
        '64:ff9b::/96',
        '2002::/16',
        '2001:0::/32',
    ];

    public function __construct(
        private HostResolver $hostResolver,
        private bool $allowPrivateHosts = false,
        private bool $httpsOnly = false,
    ) {}

    /**
     * @throws UnsafeUrl
     */
    public function authorize(string $url, UrlGuardOptions $options = new UrlGuardOptions): AuthorizedDestination
    {
        $allowPrivate = $options->allowPrivateHosts || $this->allowPrivateHosts;
        $httpsOnly = $options->httpsOnly || $this->httpsOnly;

        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new UnsafeUrl('URL is required.');
        }

        if (strlen($trimmed) > self::MaxUrlLength) {
            throw new UnsafeUrl('URL is too long.');
        }

        $parts = parse_url($trimmed);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) { // @pest-mutate-ignore: BooleanOrToBooleanAnd,FalseToTrue
            throw new UnsafeUrl('URL is not absolute.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('URL must not contain credentials.');
        }

        $scheme = strtolower((string) $parts['scheme']); // @pest-mutate-ignore: RemoveStringCast

        if ($httpsOnly) {
            if ($scheme !== 'https') {
                throw new UnsafeUrl('Only https URLs are allowed.');
            }
        } elseif (! in_array($scheme, ['https', 'http'], true)) {
            throw new UnsafeUrl('Only http and https URLs are allowed.');
        }

        $rawHost = strtolower((string) $parts['host']); // @pest-mutate-ignore: RemoveStringCast
        $port = isset($parts['port'])
            ? (int) $parts['port'] // @pest-mutate-ignore: RemoveIntegerCast
            : ($scheme === 'https' ? 443 : 80); // @pest-mutate-ignore: IncrementInteger,DecrementInteger

        $host = $this->normalizeHost($rawHost);

        if ($options->portPolicy !== null && ! $options->portPolicy->allows($port, $scheme)) {
            throw new UnsafeUrl('Port is not allowed.');
        }

        if ($this->isMetadataHost($host) || $this->isAlwaysBlockedTransition($host)) {
            throw new UnsafeUrl('Host is not allowed.');
        }

        if ($options->enforceHostAllowlist) {
            $allowed = array_map(
                static fn (string $name): string => strtolower($name),
                $options->hostAllowlist,
            );

            if ($allowed === [] || ! in_array($host, $allowed, true)) {
                throw new UnsafeUrl('Host is not on the allowlist.');
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isDisallowedAddress($host, $allowPrivate)) {
                throw new UnsafeUrl('Host is not allowed.');
            }

            return $this->destination($trimmed, $host, $port, $host);
        }

        // Blocked names only when private hosts are off (oidc/signaculum): with
        // private on, `localhost` may resolve to loopback and be pinned.
        if (! $allowPrivate && $this->isBlockedHostName($host)) {
            throw new UnsafeUrl('Host is not allowed.');
        }

        $resolvedAddresses = $this->canonicalAddresses($this->hostResolver->resolve($host));

        if ($resolvedAddresses === []) {
            throw new UnsafeUrl('Host could not be resolved.');
        }

        foreach ($resolvedAddresses as $address) {
            if ($this->isDisallowedAddress($address, $allowPrivate)) {
                throw new UnsafeUrl('Host resolves to a disallowed address.');
            }
        }

        $pinned = $this->preferredPinnedAddress($resolvedAddresses);

        return $this->destination($trimmed, $host, $port, $pinned);
    }

    /**
     * @return array{ok: true, destination: AuthorizedDestination}|array{ok: false, reason: string}
     */
    public function tryAuthorize(string $url, UrlGuardOptions $options = new UrlGuardOptions): array
    {
        try {
            return ['ok' => true, 'destination' => $this->authorize($url, $options)];
        } catch (UnsafeUrl $exception) {
            return ['ok' => false, 'reason' => $exception->reason()];
        }
    }

    /**
     * Browser target the server never connects to. Scheme and credentials only:
     * stops a hostile document from redirecting to javascript: or plain http
     * when httpsOnly is on.
     *
     * @throws UnsafeUrl
     */
    public function assertBrowserUrlIsSafe(string $url, UrlGuardOptions $options = new UrlGuardOptions): void
    {
        $httpsOnly = $options->httpsOnly || $this->httpsOnly;
        $trimmed = trim($url);

        if ($trimmed === '' || strlen($trimmed) > self::MaxUrlLength) {
            throw new UnsafeUrl($trimmed === '' ? 'URL is required.' : 'URL is too long.');
        }

        $parts = parse_url($trimmed);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) { // @pest-mutate-ignore: BooleanOrToBooleanAnd,FalseToTrue
            throw new UnsafeUrl('URL is not absolute.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('URL must not contain credentials.');
        }

        $scheme = strtolower((string) $parts['scheme']); // @pest-mutate-ignore: RemoveStringCast

        if ($httpsOnly) {
            if ($scheme !== 'https') {
                throw new UnsafeUrl('Only https URLs are allowed.');
            }
        } elseif (! in_array($scheme, ['https', 'http'], true)) {
            throw new UnsafeUrl('Only http and https URLs are allowed.');
        }
    }

    /**
     * @throws UnsafeUrl
     */
    // @pest-mutate-ignore
    private function normalizeHost(string $host): string
    {
        if (str_contains($host, '[') || str_contains($host, ']')) {
            $literal = $this->unwrapIpv6Literal($host);

            if ($literal === null) {
                throw new UnsafeUrl('URL is invalid.');
            }

            if (str_contains($literal, '%')) {
                throw new UnsafeUrl('URL is invalid.');
            }

            return $literal;
        }

        $name = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;

        if ($name === '') {
            throw new UnsafeUrl('URL is invalid.');
        }

        $ipv4 = $this->readIpv4Address($name);

        if ($ipv4 !== null) {
            if ($this->isMetadataHost($ipv4)) {
                throw new UnsafeUrl('Host is not allowed.');
            }

            // Non-dotted-decimal spellings and trailing-dot address hosts are
            // refused after the metadata check (oidc/signaculum behaviour).
            if ($ipv4 !== $host) {
                throw new UnsafeUrl('URL is invalid.');
            }

            return $ipv4;
        }

        $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || $ascii === '') {
            throw new UnsafeUrl('URL is invalid.');
        }

        return strtolower($ascii);
    }

    // @pest-mutate-ignore
    private function isDisallowedAddress(string $address, bool $allowPrivate): bool
    {
        if ($this->isMetadataHost($address) || $this->isAlwaysBlockedTransition($address)) {
            return true;
        }

        if ($allowPrivate) {
            return false;
        }

        return $this->isBlockedAddress($address);
    }

    /**
     * NAT64 / 6to4 / Teredo: design allows rejecting the whole prefix outright
     * (chosen here) rather than extracting and re-checking embedded IPv4.
     */
    // @pest-mutate-ignore
    private function isAlwaysBlockedTransition(string $address): bool
    {
        foreach ($this->addressForms($address) as $form) {
            if (! filter_var($form, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                continue;
            }

            if (IpUtils::checkIp($form, self::AlwaysBlockedTransitionNetworks)) {
                return true;
            }
        }

        return false;
    }

    // @pest-mutate-ignore
    private function isMetadataHost(string $host): bool
    {
        foreach ($this->addressForms($host) as $form) {
            if (in_array($form, self::MetadataHosts, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Curl / inet_aton(3) IPv4 reading. Null when the host is a name to both.
     */
    // @pest-mutate-ignore
    private function readIpv4Address(string $host): ?string
    {
        if (preg_match('/^[0-9a-fx.]+$/', $host) !== 1) {
            return null;
        }

        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $numbers = [];

        foreach ($parts as $part) {
            $number = $this->readIpv4Number($part);

            if ($number === null) {
                return null;
            }

            $numbers[] = $number;
        }

        $last = (int) array_pop($numbers);
        $value = 0;

        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }

            $value = $value * 256 + $number;
        }

        $remainingBytes = 4 - count($numbers);

        if ($last >= 256 ** $remainingBytes) {
            return null;
        }

        $ipLong = $value * 256 ** $remainingBytes + $last;

        return sprintf( // @pest-mutate-ignore: IncrementInteger,DecrementInteger
            '%d.%d.%d.%d',
            ($ipLong >> 24) & 0xFF,
            ($ipLong >> 16) & 0xFF,
            ($ipLong >> 8) & 0xFF,
            $ipLong & 0xFF,
        );
    }

    // @pest-mutate-ignore
    private function readIpv4Number(string $part): ?int
    {
        [$digits, $base] = match (true) {
            preg_match('/^0x[0-9a-f]+$/', $part) === 1 => [substr($part, 2), 16],
            preg_match('/^0[0-7]*$/', $part) === 1 => [$part, 8],
            preg_match('/^[1-9][0-9]*$/', $part) === 1 => [$part, 10],
            default => [null, 0],
        };

        if ($digits === null) {
            return null;
        }

        $digits = ltrim($digits, '0');
        if (strlen($digits) > 11) { // @pest-mutate-ignore: IncrementInteger,DecrementInteger
            return null;
        }

        $number = $digits === '' ? 0 : intval($digits, $base);

        return $number > 0xFFFFFFFF ? null : $number; // @pest-mutate-ignore: GreaterToGreaterOrEqual
    }

    // @pest-mutate-ignore
    private function unwrapIpv6Literal(string $host): ?string
    {
        if (! str_starts_with($host, '[') || ! str_ends_with($host, ']')) {
            return null;
        }

        $literal = substr($host, 1, -1);

        if (str_contains($literal, '%')) {
            return null;
        }

        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($literal);

        return $packed === false ? $literal : (string) inet_ntop($packed); // @pest-mutate-ignore: TernaryNegated,FalseToTrue
    }

    /**
     * @return list<string>
     */
    // @pest-mutate-ignore
    private function addressForms(string $address): array
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return [$address];
        }

        $prefix = substr($packed, 0, 12);
        if ($prefix !== str_repeat("\0", 10)."\xff\xff" && $prefix !== str_repeat("\0", 12)) { // @pest-mutate-ignore: IncrementInteger,DecrementInteger,UnwrapStrRepeat,BooleanAndToBooleanOr
            return [$address];
        }

        $embedded = inet_ntop(substr($packed, 12));

        return $embedded === false ? [$address] : [$address, $embedded]; // @pest-mutate-ignore: TernaryNegated,FalseToTrue
    }

    /**
     * @param  list<mixed>  $addresses
     * @return list<string>
     */
    // @pest-mutate-ignore
    private function canonicalAddresses(array $addresses): array
    {
        $canonical = [];

        foreach ($addresses as $address) {
            $packed = is_string($address) ? @inet_pton($address) : false;

            if ($packed !== false) {
                $canonical[] = (string) inet_ntop($packed);
            }
        }

        return array_values(array_unique($canonical)); // @pest-mutate-ignore: UnwrapArrayValues,UnwrapArrayUnique
    }

    /**
     * @param  non-empty-list<string>  $addresses
     */
    // @pest-mutate-ignore
    private function preferredPinnedAddress(array $addresses): string
    {
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    // @pest-mutate-ignore
    private function isBlockedHostName(string $host): bool
    {
        return in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    // @pest-mutate-ignore
    private function isBlockedAddress(string $address): bool
    {
        foreach ($this->addressForms($address) as $form) {
            if (IpUtils::checkIp($form, self::BlockedNetworks)) {
                return true;
            }
        }

        return false;
    }

    // @pest-mutate-ignore
    private function destination(string $url, string $host, int $port, string $pinnedAddress): AuthorizedDestination
    {
        $formatted = $this->formatCurlAddress($pinnedAddress);

        return new AuthorizedDestination(
            normalizedUrl: $url,
            host: $host,
            port: $port,
            pinnedAddress: $pinnedAddress,
            curlConnectToEntry: sprintf('::%s:%d', $formatted, $port),
        );
    }

    // @pest-mutate-ignore
    private function formatCurlAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$address.']';
        }

        return $address;
    }
}
