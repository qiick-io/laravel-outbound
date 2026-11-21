# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-21

First tagged release of `qiick/laravel-outbound`: SSRF-safe outbound HTTP and
Standard Webhooks `v1` signing/verification for Laravel 12 and 13 (PHP `^8.3`).

### Upgrade notes

- **Pre-1.0:** minor versions may break. Prefer a tagged `0.1.0` (or a later
  `0.x`) over `dev-develop` in products.
- **Install:** `composer require qiick/laravel-outbound:^0.1`, then
  `php artisan vendor:publish --tag=outbound-config`.
- **`PinnedHttpClient::send()`** configure callable receives `OutboundRequest`
  only (not Laravel `PendingRequest`). Timeout caps and the total deadline are
  config-owned via `OutboundHttpLimits` and cannot be raised per call.
- **Products** still own per-endpoint concurrency caps, circuit-break /
  auto-disable, and honouring `Retry-After` / 410 / 429. This package does not
  ship delivery tables or auto-disable.

### Changed

- `PinnedHttpClient` fails closed if `PendingRequest::$options` is missing
  (future Laravel rename), instead of silently skipping handler isolation.

- **PinnedHttpClient** strips a request-level `handler` inherited from factory
  globalOptions so a poisoned handler (Guzzle 7) cannot bypass CONNECT_TO;
  streaming size limits rely on the progress callback when Content-Length is
  absent. `OutboundRequest` also refuses `X-Host` / `X-Original-Host`. Testing
  helper: `FakeOutboundHttpFactory`.

- **PinnedHttpClient** uses a private `Http\Client\Factory` (not the shared
  Http facade factory) so `Http::globalOptions(['handler' => …])` cannot replace
  the enforcing curl wrap; any `handler` option is stripped. Caller `withQuery`
  applies to the first hop only. Opted-in cross-origin hops forward only
  Accept / Accept-Language / User-Agent / Content-Type (when a body is kept).
  `OutboundRequest` refuses Host and routing headers (`X-Forwarded-*`,
  `Forwarded`, `X-Original-URL`, `X-Rewrite-URL`). Mutation paths include
  `tests/Feature/RealTransport`.

- **Pre-1.0 API:** `PinnedHttpClient::send()` configure callable now receives
  `OutboundRequest` only (not Laravel `PendingRequest`). Transport enforcement
  wraps the curl handler; timeout caps / total deadline are config-owned via
  `OutboundHttpLimits` and cannot be raised per call. Cross-origin 307/308 are
  refused even when cross-origin redirects are opted in; bodies are dropped on
  GET/HEAD hops and on cross-origin hops.

### Added

- Standard Webhooks `WebhookSecret`, `WebhookMessage`, `SignedWebhook`,
  `StandardWebhookSigner`, and `StandardWebhookVerifier`: exact raw-body `v1`
  HMAC over `id.timestamp.body`, `whsec_` secrets (24–64 bytes), multi-secret
  space-separated signatures, bidirectional timestamp tolerance, optional
  atomic scoped `SeenWebhookIdStore::claim()` (cache `add`, TTL through
  `timestamp + tolerance + 1`, fail closed on store errors; atomic `add`
  required), payload/signature caps, full-candidate `hash_equals` (no early
  return), legacy raw-secret parsing off by default, and
  `#[\SensitiveParameter]` + redaction. `WebhookSecret` refuses
  serialize/unserialize/var_export. Official vectors from standard-webhooks
  `@ 3d65b182`. Bound from config via the service provider.

- `RetrySchedule` + `Randomizer` / `SystemRandomizer`, plus `DeliveryStatus` and
  `DeliveryAttempt`: Standard Webhooks example delays (~75.6h end-to-end,
  each from the previous attempt), short migration delays, capped jitter
  (±floor(delay/2) and configured jitter cap), and an immutable delivery
  attempt record (HTTP 100–599, nextAttemptAt ≥ at, errorMessage sanitised).
  Products own concurrency caps and auto-disable.

- `PinnedHttpClient` + `HttpRequestOptions` + `OutboundRequest` +
  `OutboundHttpLimits`: Laravel HTTP with SSRF authorize, CURLOPT_CONNECT_TO
  pin, proxies disabled, redirects off by default, optional re-auth/re-pin
  redirect loop, timeouts, and max response bytes.
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

[Unreleased]: https://github.com/qiick-io/laravel-outbound/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/qiick-io/laravel-outbound/releases/tag/v0.1.0
