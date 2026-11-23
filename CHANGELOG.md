# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Upgrade notes

- **New tables.** The provider loads five migrations (`webhook_endpoints`,
  `webhook_endpoint_secrets`, `webhook_subscriptions`, `webhook_deliveries`,
  `webhook_delivery_attempts`); run `php artisan migrate`. A product with
  tables of the same names renames its own. Before `1.0.0` these `create_`
  migrations may still change: recreate the database after an upgrade.
- **`DeliveryStatus` has three new cases** (`Pending`, `Exhausted`,
  `Cancelled`); an exhaustive `match` over it needs the new arms.
- **New dependency:** `illuminate/database` (already part of every Laravel app).

### Added

- Package migrations and Eloquent models for the five webhook delivery tables
  (standards review M1, M2, M15), loaded by the provider unless
  `outbound.migrations.load` is off, and publishable with
  `vendor:publish --tag=outbound-migrations`.
- `outbound.webhooks.scope_column` for a product's own scope column, and
  `outbound.webhooks.users_table` for the `created_by` / `triggered_by` keys.
- Enums `WebhookEndpointDisabledReasonEnum`, `WebhookDeliveryTriggerEnum`,
  `WebhookAttemptTriggerEnum`, `WebhookAttemptStatusEnum` (attempt rows:
  delivered, failed, refused) and `WebhookSignatureSchemeEnum`.
- Each model takes an explicit, minimal mass-assignment allow-list. Secret
  bytes are set only with `WebhookEndpointSecret::useSecret()`, and the body
  with its hash and length only with `WebhookDelivery::usePayload()`; both
  stay encrypted at rest and hidden from arrays and JSON, like `payload_hash`.
- CI runs the suite on PostgreSQL 17, MySQL 8.4 and MariaDB 10.11 as well as SQLite.
- Secret lifecycle (standards review M3): the `WebhookSecretResolver` contract
  with the default `DatabaseWebhookSecretResolver`, the `WebhookSigningSet`
  value object, `SecretRotator` (issue, rotate with a grace period, revoke; at
  most `max_secrets` signing secrets, one primary per endpoint), the
  `WebhookEndpointSecret::signingAt()` scope, and
  `outbound.webhooks.secret_rotation_grace_seconds` (24 h). `max_secrets`
  outside 1–4 is refused at boot.

### Changed

- The package models store and read every date as UTC, whatever
  `app.timezone` is, so grace periods and retries do not move at a
  daylight-saving change.
- `webhook_endpoints.headers` is encrypted at rest (`text`, `encrypted:array`)
  and hidden from arrays and JSON, because custom headers often carry a token.
- `WebhookEndpointSecret::replicate()` throws: a copy would be a second
  primary outside `SecretRotator`'s lock.
- `WebhookDelivery::save()` rethrows a failed write as
  `WebhookDeliveryStorageException` (`DuplicateWebhookDeliveryException` for a
  unique key) with only the SQLSTATE, and a deadlock or serialization failure
  as `WebhookDeliveryConcurrencyException` (a retryable `DeadlockException`),
  so `payload_hash` never reaches an exception message, a log or a stack-trace
  argument.
- `DeliveryStatus` gains `Pending`, `Exhausted` and `Cancelled` for delivery
  rows (standards review M7); attempts keep `Delivered`, `Failed`, `Refused`.

## [0.1.1] - 2026-11-22

### Upgrade notes

- **Catch typed webhook exceptions** instead of string-matching messages:
  `DuplicateWebhookIdException`, `WebhookIdStoreFailedException`,
  `InvalidWebhookSignatureException`. Messages are unchanged for BC.
- **Webhook id cache drivers:** use database, redis, memcached, dynamodb, or
  file (single-host only). `storage` and `failover` are refused; array is
  tests-only. Resolving `StandardWebhookVerifier` with a refused store throws
  `UnsupportedWebhookIdCacheStoreException` at resolve time.

### Fixed

- **`CacheSeenWebhookIdStore` allowlists atomic cache drivers** (database,
  redis, memcached, dynamodb, file for single-host) and refuses `storage` and
  `failover` even when they expose `add()`. Array remains tests-only.
  Resolving the verifier with a refused store throws
  `UnsupportedWebhookIdCacheStoreException` immediately.
- **Dedicated webhook exceptions** for consumers:
  `DuplicateWebhookIdException`, `WebhookIdStoreFailedException`, and
  `InvalidWebhookSignatureException` (messages unchanged for BC).

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

[Unreleased]: https://github.com/qiick-io/laravel-outbound/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/qiick-io/laravel-outbound/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/qiick-io/laravel-outbound/releases/tag/v0.1.0
