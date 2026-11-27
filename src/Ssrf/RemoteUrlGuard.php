<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Contracts\MetadataPolicyContract;
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
    public function __construct(
        private HostResolver $hostResolver,
        private bool $allowPrivateHosts = false,
        private bool $httpsOnly = false,
        private ?MetadataPolicyContract $metadataPolicy = null,
    ) {}

    /**
     * @throws UnsafeUrl
     */
    public function authorize(string $url, UrlGuardOptions $options = new UrlGuardOptions): AuthorizedDestination
    {
        // The guard's setting (ssrf.allow_private_hosts) is the ceiling: a call can only narrow it.
        $allowPrivate = $this->allowPrivateHosts && ($options->allowPrivateHosts ?? true);
        $httpsOnly = $options->httpsOnly || $this->httpsOnly;

        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new UnsafeUrl('URL is required.');
        }

        if (strlen($trimmed) > self::maxUrlLength()) {
            throw new UnsafeUrl('URL is too long.');
        }

        // isset() on the false parse_url() returns for a malformed URL is false too.
        $parts = parse_url($trimmed);
        if (! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrl('URL is not absolute.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('URL must not contain credentials.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($httpsOnly) {
            if ($scheme !== 'https') {
                throw new UnsafeUrl('Only https URLs are allowed.');
            }
        } elseif (! in_array($scheme, ['https', 'http'], true)) {
            throw new UnsafeUrl('Only http and https URLs are allowed.');
        }

        $rawHost = strtolower($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

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

        if ($trimmed === '' || strlen($trimmed) > self::maxUrlLength()) {
            throw new UnsafeUrl($trimmed === '' ? 'URL is required.' : 'URL is too long.');
        }

        $parts = parse_url($trimmed);
        if (! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrl('URL is not absolute.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('URL must not contain credentials.');
        }

        $scheme = strtolower($parts['scheme']);

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
    private function normalizeHost(string $host): string
    {
        // A bracket anywhere else is refused with the other characters below.
        if (str_starts_with($host, '[')) {
            $literal = $this->unwrapIpv6Literal($host);

            if ($literal === null) {
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

        // Nontransitional UTS46: full-width digits/punctuation must not mint an
        // IP-like hostname (closed below). Underscores stay allowed (curl/S3).
        $ascii = idn_to_ascii(
            $name,
            IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46,
        );

        if ($ascii === false) {
            throw new UnsafeUrl('URL is invalid.');
        }

        // Ideographic full stop and similar can map to '.' — strip after IDN.
        if (str_ends_with($ascii, '.')) {
            $ascii = substr($ascii, 0, -1);
        }

        // Dot-separated labels of letters, digits, hyphens and underscores
        // (curl and S3 accept them), nothing else and never empty; an IP must
        // arrive as a URL IP literal, never through IDN mapping.
        if (preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/D', $ascii) !== 1 || $this->readIpv4Address($ascii) !== null) {
            throw new UnsafeUrl('URL is invalid.');
        }

        return $ascii;
    }

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
     * IpUtils matches IPv6 prefixes only against IPv6 addresses, so a name or
     * an IPv4 address never matches.
     */
    private function isAlwaysBlockedTransition(string $address): bool
    {
        return IpUtils::checkIp($address, self::alwaysBlockedTransitionNetworks());
    }

    /**
     * Every host and address reaching here is canonical (a lowercased name,
     * a dotted-decimal IPv4, an inet_ntop() IPv6), like the list entries.
     */
    private function isMetadataHost(string $host): bool
    {
        return in_array($this->comparableForm($host), [...self::metadataHosts(), ...$this->additionalMetadataHosts()], true);
    }

    /**
     * The policy's hosts in the form the guard compares: a canonical IP
     * address, or a lowercased name without its trailing dot.
     *
     * @return list<string>
     */
    private function additionalMetadataHosts(): array
    {
        return array_map(
            static fn (string $host): string => filter_var($host, FILTER_VALIDATE_IP) !== false ? self::canonicalIp($host) : rtrim(strtolower($host), '.'),
            $this->metadataPolicy?->additionalMetadataHosts() ?? [],
        );
    }

    /**
     * Curl / inet_aton(3) IPv4 reading: one to four parts in decimal, octal
     * (leading 0) or hex (0x), the last part filling the remaining bytes. Null
     * when the host is a name to both.
     */
    private function readIpv4Address(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $last = $this->readIpv4Number(array_pop($parts));

        if ($last === null || $last >= 256 ** (4 - count($parts))) {
            return null;
        }

        $ipLong = $last;

        // Each leading part is one byte, from the most significant down.
        foreach ($parts as $position => $part) {
            $number = $this->readIpv4Number($part);

            if ($number === null || $number > 255) {
                return null;
            }

            $ipLong += $number * 256 ** (3 - $position);
        }

        return sprintf(
            '%d.%d.%d.%d',
            ($ipLong >> 24) & 0xFF,
            ($ipLong >> 16) & 0xFF,
            ($ipLong >> 8) & 0xFF,
            $ipLong & 0xFF,
        );
    }

    /**
     * An overlong part saturates intval() at PHP_INT_MAX, which the byte
     * limits in readIpv4Address() then refuse.
     */
    private function readIpv4Number(string $part): ?int
    {
        // intval() reads the `0x` prefix in base 16 and a leading 0 in base 8.
        $base = match (true) {
            preg_match('/^0x[0-9a-f]+$/', $part) === 1 => 16,
            preg_match('/^0[0-7]*$/', $part) === 1 => 8,
            preg_match('/^[1-9][0-9]*$/', $part) === 1 => 10,
            default => null,
        };

        return $base === null ? null : intval($part, $base);
    }

    private function unwrapIpv6Literal(string $host): ?string
    {
        if (preg_match('/^\[(.*)\]$/D', $host, $bracketed) !== 1) {
            return null;
        }

        // filter_var() also refuses a zone id (`fe80::1%eth0`).
        $literal = $bracketed[1];

        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        return self::canonicalIp($literal);
    }

    /**
     * The embedded IPv4 address of an IPv4-mapped (::ffff:0:0/96) or
     * IPv4-compatible (::/96) address, the form the lists judge it by;
     * anything else as it is. `::` and `::1` are compatible forms of 0.0.0.0
     * and 0.0.0.1, inside 0.0.0.0/8.
     */
    private function comparableForm(string $address): string
    {
        $packed = (string) @inet_pton($address);
        $prefix = substr($packed, 0, 12);

        if ($prefix !== str_repeat("\0", 10)."\xff\xff" && $prefix !== str_repeat("\0", 12)) {
            return $address;
        }

        return implode('.', array_map(ord(...), str_split(substr($packed, 12))));
    }

    /**
     * The resolver's answers that are IP addresses, in canonical form; the
     * pin is the first IPv4 one, so duplicates change nothing.
     *
     * @param  list<mixed>  $addresses
     * @return list<string>
     */
    private function canonicalAddresses(array $addresses): array
    {
        $canonical = [];

        foreach ($addresses as $address) {
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $canonical[] = self::canonicalIp($address);
            }
        }

        return $canonical;
    }

    /**
     * @param  non-empty-list<string>  $addresses
     */
    private function preferredPinnedAddress(array $addresses): string
    {
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    private function isBlockedHostName(string $host): bool
    {
        return in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    private function isBlockedAddress(string $address): bool
    {
        return IpUtils::checkIp($this->comparableForm($address), self::blockedNetworks());
    }

    /**
     * The canonical text of an address filter_var() already accepted.
     */
    private static function canonicalIp(string $address): string
    {
        // Both functions fail only on an address filter_var() refused, which
        // never reaches here; the address as given would then be the answer.
        return inet_ntop(inet_pton($address) ?: $address) ?: $address;
    }

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

    private function formatCurlAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$address.']';
        }

        return $address;
    }

    /**
     * Blocked when private hosts are not allowed.
     *
     * A method rather than a constant, like the lists below: a constant's
     * lines have no coverage, so mutation testing could not check that each
     * entry is needed.
     *
     * @return list<string>
     */
    private static function blockedNetworks(): array
    {
        return [
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
            'fc00::/7',
            'fe80::/10',
            'fec0::/10', // site-local (deprecated RFC 3879); blocked when private off
            'ff00::/8',
            '2001:db8::/32',
        ];
    }

    /**
     * Always blocked, also with allowPrivateHosts. Values for IP entries are the
     * canonical inet_ntop(inet_pton()) form so isMetadataHost can compare after
     * canonicalisation.
     *
     * @return list<string>
     */
    private static function metadataHosts(): array
    {
        return [
            'metadata',
            'metadata.google.internal',
            '169.254.169.254', // EC2/GCP link-local metadata
            'fd00:ec2::254', // EC2 IPv6 metadata
            '100.100.100.200', // Alibaba Cloud metadata
            '192.0.0.192', // Oracle Cloud metadata
            '169.254.170.2', // AWS ECS task metadata
            '169.254.170.23', // AWS EKS pod IPv4 metadata
            'fd00:ec2::23', // AWS EKS pod IPv6 metadata
            'fd20:ce::254', // GCP IPv6 metadata
            '168.63.129.16', // Azure wireserver / platform DNS
        ];
    }

    /**
     * Transition prefixes always blocked (even when private hosts are allowed).
     *
     * @return list<string>
     */
    private static function alwaysBlockedTransitionNetworks(): array
    {
        return [
            '64:ff9b::/96', // NAT64 well-known prefix (RFC 6052)
            '64:ff9b:1::/48', // local-use NAT64 (RFC 8215); always — embedded metadata via local gateway
            '2002::/16', // 6to4 (RFC 3056) — rejected outright
            '2001:0::/32', // Teredo (RFC 4380)
        ];
    }

    /**
     * The longest URL accepted, in bytes.
     */
    private static function maxUrlLength(): int
    {
        return 2048;
    }
}
