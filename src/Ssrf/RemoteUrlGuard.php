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
        'fec0::/10', // site-local (deprecated RFC 3879); blocked when private off
        'ff00::/8',
        '2001:db8::/32',
        '64:ff9b:1::/48', // local-use NAT64 (RFC 8215); blocked when private off
    ];

    /**
     * Always blocked, also with allowPrivateHosts. Values for IP entries are the
     * canonical inet_ntop(inet_pton()) form so isMetadataHost can compare after
     * canonicalisation.
     *
     * @var list<string>
     */
    private const array MetadataHosts = [
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

    /**
     * Transition prefixes always blocked (even when private hosts are allowed).
     *
     * @var list<string>
     */
    private const array AlwaysBlockedTransitionNetworks = [
        '64:ff9b::/96', // NAT64 well-known prefix (RFC 6052)
        '2002::/16', // 6to4 (RFC 3056) — rejected outright
        '2001:0::/32', // Teredo (RFC 4380)
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
        // parse_url does not return false for the string inputs we accept.
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) { // @pest-mutate-ignore: FalseToTrue
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
            : ($scheme === 'https' ? 443 : 80);

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
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) { // @pest-mutate-ignore: FalseToTrue
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
    private function normalizeHost(string $host): string
    {
        if (str_contains($host, '[') || str_contains($host, ']')) {
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

        // STD3 + nontransitional: full-width digits/punctuation must not mint
        // ':' into a hostname that later parses as IPv6 (IDN bypass).
        $ascii = idn_to_ascii(
            $name,
            IDNA_USE_STD3_RULES | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46,
        );

        if ($ascii === false || $ascii === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            throw new UnsafeUrl('URL is invalid.');
        }

        $ascii = strtolower($ascii);

        // Ideographic full stop and similar can map to '.' — strip after IDN.
        if (str_ends_with($ascii, '.')) {
            $ascii = substr($ascii, 0, -1);
        }

        if ($ascii === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            throw new UnsafeUrl('URL is invalid.');
        }

        // An IP must arrive as a URL IP literal, never through IDN mapping.
        // STD3 already rejects most of these; the OR chain is defense in depth.
        if (strpbrk($ascii, ':[]%@/\\?#') !== false || filter_var($ascii, FILTER_VALIDATE_IP) !== false || $this->readIpv4Address($ascii) !== null) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
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
     */
    private function isAlwaysBlockedTransition(string $address): bool
    {
        foreach ($this->addressForms($address) as $form) {
            if (! filter_var($form, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            if (IpUtils::checkIp($form, self::AlwaysBlockedTransitionNetworks)) {
                return true;
            }
        }

        return false;
    }

    private function isMetadataHost(string $host): bool
    {
        foreach ($this->addressForms($host) as $form) {
            foreach ($this->metadataCompareForms($form) as $candidate) {
                if (in_array($candidate, self::MetadataHosts, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function metadataCompareForms(string $form): array
    {
        $forms = [$form];
        $packed = @inet_pton($form);

        if ($packed !== false) {
            $canonical = inet_ntop($packed);

            if ($canonical !== false) { // @pest-mutate-ignore: IfNegated,NotIdenticalToIdentical,FalseToTrue
                $forms[] = $canonical;
            }
        }

        return array_values(array_unique($forms)); // @pest-mutate-ignore: UnwrapArrayUnique,UnwrapArrayValues
    }

    /**
     * Curl / inet_aton(3) IPv4 reading. Null when the host is a name to both.
     */
    private function readIpv4Address(string $host): ?string
    {
        if (preg_match('/^[0-9a-fx.]+$/', $host) !== 1) {
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
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

        $last = (int) array_pop($numbers); // @pest-mutate-ignore: RemoveIntegerCast
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

        // Power→multiply is equivalent on dotted-decimal (remainingBytes=1) and on
        // short forms that authorize() refuses after the spelling check anyway.
        $ipLong = $value * 256 ** $remainingBytes + $last; // @pest-mutate-ignore: PowerToMultiplication

        return sprintf( // @pest-mutate-ignore: DecrementInteger
            '%d.%d.%d.%d',
            ($ipLong >> 24) & 0xFF, // @pest-mutate-ignore: DecrementInteger
            ($ipLong >> 16) & 0xFF, // @pest-mutate-ignore: DecrementInteger
            ($ipLong >> 8) & 0xFF, // @pest-mutate-ignore: DecrementInteger
            $ipLong & 0xFF,
        );
    }

    private function readIpv4Number(string $part): ?int
    {
        [$digits, $base] = match (true) {
            // substr offset / base integers: authorize() only sees dotted-decimal vs refuse-as-name
            preg_match('/^0x[0-9a-f]+$/', $part) === 1 => [substr($part, 2), 16], // @pest-mutate-ignore: UnwrapSubstr,DecrementInteger,IncrementInteger
            preg_match('/^0[0-7]*$/', $part) === 1 => [$part, 8], // @pest-mutate-ignore: DecrementInteger,IncrementInteger
            preg_match('/^[1-9][0-9]*$/', $part) === 1 => [$part, 10], // @pest-mutate-ignore: DecrementInteger,IncrementInteger
            default => [null, 0], // @pest-mutate-ignore: DecrementInteger,IncrementInteger
        };

        if ($digits === null) {
            return null;
        }

        $digits = ltrim($digits, '0');

        if (strlen($digits) > 11) {
            return null;
        }

        $number = $digits === '' ? 0 : intval($digits, $base); // @pest-mutate-ignore: EmptyStringToNotEmpty

        return $number > 0xFFFFFFFF ? null : $number;
    }

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

        // inet_pton cannot fail after FILTER_VALIDATE_IP succeeded for the same literal.
        return $packed === false ? $literal : (string) inet_ntop($packed); // @pest-mutate-ignore: TernaryNegated,FalseToTrue,IdenticalToNotIdentical,RemoveStringCast
    }

    /**
     * @return list<string>
     */
    private function addressForms(string $address): array
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return [$address];
        }

        $prefix = substr($packed, 0, 12);

        // Mapped (::ffff:0:0/96) and compatible (::/96) IPv4-in-IPv6 forms.
        if ($prefix !== str_repeat("\0", 10)."\xff\xff" && $prefix !== str_repeat("\0", 12)) {
            return [$address];
        }

        $embedded = inet_ntop(substr($packed, 12));

        // Four bytes from a valid v6 pack always yield an IPv4 string from inet_ntop.
        return $embedded === false ? [$address] : [$address, $embedded]; // @pest-mutate-ignore: TernaryNegated,FalseToTrue,RemoveArrayItem
    }

    /**
     * @param  list<mixed>  $addresses
     * @return list<string>
     */
    private function canonicalAddresses(array $addresses): array
    {
        $canonical = [];

        foreach ($addresses as $address) {
            $packed = is_string($address) ? @inet_pton($address) : false; // @pest-mutate-ignore: FalseToTrue

            if ($packed !== false) {
                $canonical[] = (string) inet_ntop($packed); // @pest-mutate-ignore: RemoveStringCast
            }
        }

        // Single preferred pin does not depend on duplicate/order of resolver answers.
        return array_values(array_unique($canonical)); // @pest-mutate-ignore: UnwrapArrayValues,UnwrapArrayUnique
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
        foreach ($this->addressForms($address) as $form) {
            if (IpUtils::checkIp($form, self::BlockedNetworks)) {
                return true;
            }
        }

        return false;
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
}
