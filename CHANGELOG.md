# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `PinnedHttpClient` + `HttpRequestOptions`: Laravel HTTP with SSRF authorize,
  CURLOPT_CONNECT_TO pin, proxies disabled, redirects off by default, optional
  re-auth/re-pin redirect loop, timeouts, and max response bytes.
- `scripts/mutate-per-class.sh` — per-class pest mutate ≥95% (CI-enforced).
- SSRF `RemoteUrlGuard` with `HostResolver`, `UrlGuardOptions`, `PortPolicy`,
  `AuthorizedDestination` (CURLOPT_CONNECT_TO only), `UnsafeUrl`,
  `DnsHostResolver`, and `FakeHostResolver`. Covers curl-style IPv4, metadata
  (EC2/GCP/Alibaba/Oracle), NAT64/6to4/Teredo, IDN punycode, zone ids,
  resolve-all, allowlist and port policy hooks.
- Package skeleton: auto-discovered `OutboundServiceProvider`, publishable
  `config/outbound.php` (tag `outbound-config`) with the `OUTBOUND_*` env keys
  from the approved design, Pest + Testbench, Pint, PHPStan at level max,
  architecture tests, and CI (PHP 8.3/8.4/8.5 × Laravel 12/13 × lowest/stable,
  `composer validate --strict`, `composer audit`, standards drift against
  app-ui `v0.11.0`).
