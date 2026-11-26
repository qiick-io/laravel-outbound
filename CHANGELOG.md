# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0-beta.3] - 2026-11-26

Third pre-release: one security fix from the signaculum review. Public APIs
may still change in any 0.x minor; recreate the database after each pre-1.0
upgrade.

### Upgrade notes

- **`webhook_endpoints.allow_private_hosts` is `NOT NULL DEFAULT false`.**
  An endpoint left at null used to inherit the global setting when the
  webhook was sent; it now stays off private hosts until the product sets it
  to `true`. Recreate the database, or backfill nulls to `false` and alter
  the column (see `UPGRADING.md`).

### Security

- **An endpoint's `allow_private_hosts` is never null and defaults to
  `false`.** A null inherited the global ceiling as it was when the webhook
  was sent, so turning `ssrf.allow_private_hosts` on later opened private
  hosts for every endpoint left at null. An endpoint now reaches private
  hosts only when it is `true` and the global setting allows them; the
  coordinator also treats anything but `true` as `false`.

## [0.2.0-beta.2] - 2026-11-26

Second pre-release, from the signaculum and excubia adoptions. Public APIs
may still change in any 0.x minor; recreate the database after each pre-1.0
upgrade.

### Upgrade notes

- **Breaking, private hosts: `ssrf.allow_private_hosts` is now the ceiling.**
  A call's `UrlGuardOptions(allowPrivateHosts: true)` no longer opens
  private hosts on a guard that refuses them, and
  `outbound.webhooks.allow_endpoint_private_hosts` is removed: an endpoint's
  `allow_private_hosts` can only narrow the global setting (null inherits,
  `false` refuses, `true` never widens). A product that let some endpoints
  reach internal hosts with the global setting off turns it on and sets
  `allow_private_hosts = false` on every endpoint that must stay public.
  Details in `UPGRADING.md`.
- The `create_` migrations index three foreign keys: run `migrate:fresh`, or
  add the three indexes listed in `UPGRADING.md` to a beta.1 database.

### Added

- `WebhookDeliveredEvent`: fired once a 2xx attempt is stored, after the
  commit, with the delivery's and the endpoint's public ids, the webhook id
  and the attempt number; the counterpart of `WebhookDeliveryFailedEvent`.
- A time budget for a worker run: `WebhookDeliveryCoordinator::work($limit,
  $maxSeconds)` and `outbound:webhooks:work --max-seconds` start no attempt
  once the budget is spent, next to the count limit.
- `WebhookEndpoint::displayHost()`: the endpoint's host, or "an unknown host",
  for a product's own notices, as the disable email names it (it was private
  to the listener).
- `Contracts\WebhookEndpointNoticeRecipientsContract`: a product chooses who
  gets the endpoint-disabled email (users, a team, a routed address) instead
  of turning `notify_owner` off; the package binds the `created_by` user.

### Fixed

- `webhook_endpoints.created_by`, `webhook_deliveries.next_attempt_triggered_by`
  and `webhook_delivery_attempts.triggered_by` have an index: PostgreSQL and
  SQLite do not create one for a foreign key, so deleting a user scanned
  those tables. A test requires an index on every foreign key of the package
  tables, on all four engines.

### Security

- **`ssrf.allow_private_hosts` is a ceiling.** `RemoteUrlGuard` used to OR
  it with the per-call `UrlGuardOptions::$allowPrivateHosts`, and a webhook
  endpoint could reach private hosts with the global setting off through
  `outbound.webhooks.allow_endpoint_private_hosts`. A call or an endpoint now
  only narrows the global setting: `UrlGuardOptions::$allowPrivateHosts` is
  `?bool` (null inherits, false refuses, true never widens), and the
  `allow_endpoint_private_hosts` key is removed.

## [0.2.0-beta.1] - 2026-11-25

First pre-release with webhook delivery in the package (tables, coordinator,
retries, auto-disable, redelivery, body retention, egress proxy, throttle).
Public APIs may still change in any 0.x minor; recreate the database after
each pre-1.0 upgrade.

### Upgrade notes

- **New tables.** The provider loads five migrations (`webhook_endpoints`,
  `webhook_endpoint_secrets`, `webhook_subscriptions`, `webhook_deliveries`,
  `webhook_delivery_attempts`); run `php artisan migrate`. A product with
  tables of the same names renames its own. Before `1.0.0` these `create_`
  migrations may still change: recreate the database after an upgrade.
- **`DeliveryStatus` has three new cases** (`Pending`, `Exhausted`,
  `Cancelled`); an exhaustive `match` over it needs the new arms.
- **New dependencies:** `illuminate/database` and `illuminate/notifications`
  (already part of every Laravel app).
- **Auto-disable is on by default:** an endpoint that answers 410, or has no
  success for 5 days with at least 10 consecutive failures, stops receiving
  deliveries, and its creator is emailed. Re-enable it with
  `WebhookDeliveryCoordinator::enableEndpoint()`.
- **`webhook_deliveries.payload_expires_at` is required**: a product that
  inserts delivery rows itself sets it. A row without it was never deleted
  by the prune.
- **Guzzle 7.12 is the minimum on the 7 line** (`^7.12 || ^8.0`): older 7.x
  leave `CURLOPT_NOPROXY` unset, so `NO_PROXY` in the environment could send
  requests around the egress proxy.
- **`SeenWebhookIdStore::claim()` takes a `WebhookIdClaim`** instead of the
  scope and id (`WebhookIdClaim::generate($scope, $webhookId)`); a custom
  store implements the new signature and `release(WebhookIdClaim)`, freeing
  the id only for the claim's token. `StandardWebhookVerifier::verify()`
  returns the claim it won, or null without a store.
- **`RetrySchedule::MAX_DELAY_SECONDS` is now `RetrySchedule::maxDelaySeconds()`**,
  and `MAX_JITTER_SECONDS` is no longer public (both values are unchanged).
- **`DeliveryAttempt::MAX_ERROR_MESSAGE_BYTES` is now
  `DeliveryAttempt::maxErrorMessageBytes()`** (still 500).
- **A config value of the wrong type stops the boot**, naming the key (for
  example a string where `outbound.webhooks.payload_retention_days` takes an
  integer), and an unknown `outbound.webhooks.retry_preset` is refused;
  before, both silently fell back to the default. A key missing from a
  published config still takes the package default.
- **`OutboundRequest::asMultipart()` takes a list of parts**, as Guzzle's
  `multipart` option does; `fromSnapshot()` now restores a multipart body
  (it refused every one).
- **`RemoteUrlGuard` refuses a host name outside dot-separated letters,
  digits, hyphens and underscores** ("URL is invalid."); before, a name such
  as `exa!mple.com` reached the resolver.

- **`StandardWebhookVerifier::verify()` requires `dedupeScope`**: the shared
  `'default'` made two tenants block each other's identical `webhook-id`.
- **The product's scope column is no longer mass-assignable** on
  `WebhookEndpoint`: set it with `forceFill()` in the product's own code.

### Added

- Dependency register `docs/dependencies.md`: one row per runtime
  `composer.json` `require` entry with constraint rationale, usage,
  maintainer, license, known risk and an in-package exit plan.
- Per-endpoint throttle (standards review S7): `rate_limit_per_minute` caps
  the attempts started per minute, and a 429, 502, 503 or 504 halves the
  endpoint's concurrency until its next success. `webhook_endpoints` gains
  `rate_window_started_at`, `rate_window_attempts` and `throttled_at`.

- `Contracts\MetadataPolicyContract` (standards review S4): a product adds
  metadata hosts the guard always refuses; the union with the package's list
  can never unblock one.
- `WebhookDeliveryFailedEvent` (S5): fired when an attempt leaves a delivery
  exhausted, with public ids and the reason only.
- `Contracts\LegacyWebhookProfileContract` and
  `outbound.webhooks.legacy_profiles` (S8): a legacy format's superset body,
  stored and signed at dispatch, and its headers on each attempt, without a
  condition in the package.
- Standards review gaps: `SeenWebhookIdStore::release()` (M14), implemented by
  `CacheSeenWebhookIdStore` and `FakeSeenWebhookIdStore`, usable only with
  the `WebhookIdClaim` that `verify()` returned; the events
  `WebhookSignedEvent` and `OutboundRequestBlockedEvent` (M13), with no
  secrets or bodies.
- Egress proxy (standards review R3, S1): `outbound.http.egress_proxy` and
  `EgressProxy` route `PinnedHttpClient` through an http(s) forward proxy in
  forced tunnel mode, so the proxy only receives `CONNECT` to the address
  the guard approved; credentials never appear as text, in a dump or in an
  exception trace, and `NO_PROXY` cannot bypass it. A value other than an
  http(s) proxy URL, null or an empty string stops the boot. Real-transport
  tests run through a CONNECT proxy on Guzzle 7 and 8.
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
- `OutboundHttpException::transportErrorKind()` and
  `OutboundTransportErrorKindEnum` (`timeout`, `tls`, `dns`, `connection`):
  `PinnedHttpClient` says which kind of transport error it hit, read from the
  curl errno on Guzzle 7 and 8, without the host or address in the message.

- Webhook delivery (standards review M5, M6, M9, R4, R9, S2):
  `WebhookDeliveryCoordinator` (`dispatch()`, `work()`) and the
  `outbound:webhooks:work` command, sending every attempt through
  `PinnedHttpClient` with a 15 s webhook timeout
  (`outbound.webhooks.timeout_seconds`); `RetryAfter::parse()` (delay-seconds
  and all three HTTP-date formats); `RetryPolicy` and `RetryDecision`
  (jitter first, never below a 429/502/503/504 `Retry-After`, a delay above
  `retry_after_cap_seconds` stops retries, 410 stops at once);
  `WebhookRetryPresetEnum`, `WebhookExhaustedReasonEnum`; per-endpoint
  concurrency (`default_max_concurrency`) through a per-delivery
  `claimed_until` lease taken just before each send, and fair selection
  across endpoints; `webhook_deliveries.claimed_until` and `exhausted_reason`;
  `outbound.webhooks.allow_endpoint_private_hosts` (off), without which an
  endpoint's `allow_private_hosts` is ignored.
- `DeliveryAttempt::sanitiseErrorMessage()` is public.
- Manual and bulk redelivery (standards review M11, R7, S5):
  `WebhookDeliveryCoordinator::redeliver()` and `redeliverRange()` (endpoint,
  time range, statuses) reuse the delivery's `webhook-id` with a fresh
  timestamp and signatures, record `trigger = manual|bulk` and `triggered_by`
  on the attempt, and fire `WebhookRedeliveryRequestedEvent`;
  `webhook_deliveries.next_attempt_trigger` and `next_attempt_triggered_by`.
- Body retention (standards review M12, R5): deliveries keep their encrypted
  body until `payload_expires_at`, `outbound.webhooks.payload_retention_days`
  (30, at least 1) after dispatch or the end of their longest retry plan if
  later, and `outbound:webhooks:prune` clears it in blocks, keeping
  `payload_hash` and `payload_bytes`, then deletes settled deliveries older
  than `outbound.webhooks.delivery_retention_days` (90) with their attempts;
  a pending delivery whose body expires is exhausted with the new reason
  `payload_expired`.
- A failed attempt's `error_code` names the transport error kind (`timeout`,
  `tls`, `dns`, `connection`); `transport` remains for a client refusal
  without one.
- Auto-disable (standards review M10, R8, S5): `EndpointDisablePolicy` (410
  Gone at once; otherwise 5 days without a success and at least 10
  consecutive failures, `outbound.webhooks.auto_disable.*`, overridden by an
  endpoint's `auto_disable`), `WebhookEndpointDisabledEvent`,
  `WebhookEndpointDisabledNotification` emailed to the endpoint's
  `created_by` by `NotifyWebhookEndpointOwnerListener` (automatic disables
  only, `notify_owner`; host, public id and a link to the product's
  `endpoint_url`, never tenant text), and `WebhookDeliveryCoordinator::disableEndpoint()` /
  `enableEndpoint()`.

### Changed

- `StandardWebhookSigner` refuses a `webhook-id` containing `.` (M4), and
  `WebhookMessage` validates its id (no `.`, no control characters, at most
  191 bytes) and its event type (dot-separated `[A-Za-z0-9_]` segments, S3).
- `webhook_endpoints.allow_private_hosts`, `auto_disable` and `created_by`
  are no longer mass-assignable.
- A transport timeout, TLS or DNS failure now reads `Outbound request failed:
  timeout.` / `TLS error.` / `DNS error.` instead of `connection error.`.
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

- **`StandardWebhookVerifier::verify()` requires `dedupeScope`**: the shared
  `'default'` made two tenants block each other's identical `webhook-id`.
- **The product's scope column is no longer mass-assignable** on
  `WebhookEndpoint`: set it with `forceFill()` in the product's own code.

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

[Unreleased]: https://github.com/qiick-io/laravel-outbound/compare/v0.2.0-beta.3...HEAD
[0.2.0-beta.3]: https://github.com/qiick-io/laravel-outbound/compare/v0.2.0-beta.2...v0.2.0-beta.3
[0.2.0-beta.2]: https://github.com/qiick-io/laravel-outbound/compare/v0.2.0-beta.1...v0.2.0-beta.2
[0.2.0-beta.1]: https://github.com/qiick-io/laravel-outbound/compare/v0.1.1...v0.2.0-beta.1
[0.1.1]: https://github.com/qiick-io/laravel-outbound/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/qiick-io/laravel-outbound/releases/tag/v0.1.0
