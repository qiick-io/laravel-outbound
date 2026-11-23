<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/header-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/header-light.svg">
    <img alt="laravel-outbound: Safe outbound HTTP and webhooks for Laravel" src=".github/assets/header-light.svg" width="100%">
  </picture>
</p>

# qiick/laravel-outbound

> **Status:** `0.1.1`. Pre-1.0: minor versions may break. Prefer a tagged release
> over `dev-develop`. See [CHANGELOG](./CHANGELOG.md) Upgrade notes.

SSRF-safe outbound HTTP and Standard Webhooks signing for qiick Laravel products.

## Status

Released as **`0.1.1`** against the approved design
(`projects/qiick/packages/laravel-outbound-design.md` in the qiick knowledge
base). Public APIs, evaluation order and security properties follow SemVer;
before `1.0.0`, a minor may break.

## Purpose

One canonical path for:

- **SSRF-safe outbound HTTP** — curl-style IPv4 reading, metadata blocking
  (including Alibaba/Oracle even when private hosts are allowed), resolve-all
  DNS checks, and `CURLOPT_CONNECT_TO` pinning (never `CURLOPT_RESOLVE` alone).
- **Standard Webhooks** — symmetric `v1` signing and verification over the
  exact raw body bytes, multi-secret rotation bounds, and pure retry schedules.

- **Webhook delivery storage** — the five delivery tables ship as package
  migrations, so every product stores endpoints, secrets, subscriptions,
  deliveries and attempts in one shape (see [Webhook tables](#webhook-tables)).

Non-goals for v1: asymmetric Standard Webhooks (`v2a`; its columns are
reserved), global helpers, a hard dependency on `laravel/framework`. The
delivery layer the 2026-11-22 standards review made mandatory before 1.0
(delivery coordinator, Retry-After, auto-disable, redelivery, secret
lifecycle) lands in the following pull requests.

## Threat model

Attackers who control a destination URL (webhook endpoint, IdP discovery URL,
user-supplied fetch target) must not reach:

- Cloud metadata (`169.254.169.254`, `fd00:ec2::254`, `100.100.100.200`,
  `192.0.0.192`, `metadata` / `metadata.google.internal`)
- Loopback, private, CGNAT, link-local, ULA, or unspecified addresses when
  private hosts are disallowed
- Transition prefixes that re-embed IPv4 (NAT64, 6to4, Teredo)
- DNS-rebinding races (pin the checked address with `CURLOPT_CONNECT_TO` on
  every hop; redirects never use transport auto-follow)
- Signature forgery on inbound Standard Webhooks (exact raw body, integer
  timestamps, tolerance window, `hash_equals`); consumers must also dedupe
  `webhook-id` within the tolerance window

Products that adopt this package must still enforce per-endpoint concurrency
caps, timeouts, circuit-break / auto-disable after repeated failures, and
honour `Retry-After` / 410 / 429. The package documents that duty; it does not
ship auto-disable.

## Requirements

- PHP ^8.3 with `ext-curl`, `ext-intl`, and `ext-mbstring`
- illuminate/contracts, illuminate/http, illuminate/support ^12 or ^13
- guzzlehttp/guzzle ^7.8 or ^8.0

## Install

```bash
composer require qiick/laravel-outbound:^0.1
php artisan vendor:publish --tag=outbound-config
```

The service provider is auto-discovered.

## Public API

### SSRF

```php
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UrlGuardOptions;
use Qiick\Outbound\Testing\FakeHostResolver;

$guard = new RemoteUrlGuard(new FakeHostResolver(['example.test' => ['93.184.216.34']]));
$destination = $guard->authorize('https://example.test/hook');
// $destination->guzzleCurlOptions() → CURLOPT_CONNECT_TO pin only
```

`assertBrowserUrlIsSafe()` checks scheme and credentials for URLs the server
never connects to (browser redirects).

### Pinned HTTP client

```php
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundRequest;
use Qiick\Outbound\Http\PinnedHttpClient;

$client = app(PinnedHttpClient::class);
$response = $client->send(
    'POST',
    'https://example.test/hook',
    function (OutboundRequest $request): void {
        $request->asJson(['event' => 'created'])->accept('application/json');
    },
    new HttpRequestOptions(followRedirects: true, maxRedirects: 3),
);
```

The configure callable receives a package-owned {@see OutboundRequest} only
(headers, query on the authorised URL, raw/json/form/multipart body, Accept /
Content-Type). It never sees Laravel's `PendingRequest`, so it cannot change
the URI, timeouts, proxy, pin, redirects, verify, or stream. Transport settings
are re-applied by wrapping the curl handler.

Transport never auto-follows; when `followRedirects` is on, each `Location` is
resolved, SSRF-checked, and re-pinned. Cross-origin redirects are off by
default; when opted in, only 301/302/303 are allowed (body dropped) — cross-origin
307/308 are always refused, and only Accept / Accept-Language / User-Agent /
Content-Type (when a body is kept) are forwarded. Caller query values apply to
the first hop only. `OutboundRequest` refuses Host / X-Host / X-Original-Host and routing headers (`X-Forwarded-*`, `Forwarded`, `X-Original-URL`, `X-Rewrite-URL`). The
client uses a private HTTP factory so shared `Http::globalOptions(['handler'])`
cannot bypass the pin. Environment proxies are disabled so they cannot bypass
CONNECT_TO. Timeout caps and the total redirect deadline come from
`outbound.http.*` config and cannot be raised per call.


### Testing `PinnedHttpClient`

`Http::fake()` and `Http::globalOptions()` on the shared facade factory do **not**
reach `PinnedHttpClient` when the service provider binds a private factory. Each
transfer also strips a request-level `handler` from factory global options so a
poisoned factory cannot bypass CONNECT_TO on Guzzle 7. In tests, inject a factory
from `Qiick\Outbound\Testing\FakeOutboundHttpFactory::fake([...])` (or `::make()`),
or use the `transferHandler` constructor seam for unit tests without a real client.


### Standard Webhooks

```php
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookSecret;

$secret = WebhookSecret::parse('whsec_…'); // 24–64 raw bytes after base64
$signer = app(StandardWebhookSigner::class);
$signed = $signer->sign($msgId, $unixSeconds, $rawBody, [$secret]);

$verifier = app(StandardWebhookVerifier::class); // cache-backed webhook-id dedupe
$verifier->verify(
    $rawBody,
    $signed->headers['webhook-id'],
    $signed->headers['webhook-timestamp'],
    $signed->headers['webhook-signature'],
    [$secret],
    new DateTimeImmutable('now'),
    // Scope MUST identify the secret or endpoint — never share 'default' across tenants.
    dedupeScope: 'orders-webhook',
);
```

Signing uses exact raw body bytes (`msg_id.timestamp.body`). Multi-secret
rotation emits space-separated `v1,<base64>` signatures. Verification fails
closed on non-integer timestamps, skew outside the tolerance window (both
directions), oversize payloads, too many signatures/secrets, and uses
`hash_equals` against every candidate (no early return). Timestamp tolerance
alone is **not** replay protection: after a signature match the verifier
atomically `claim`s `(scope, webhook-id)` until `timestamp + tolerance + 1`
(covers sender skew; holds through the last accepted second). The seen-id
cache allowlist is **database, redis, memcached, dynamodb, and file**
(file is single-host only). `storage` and `failover` are refused even when
they expose `add()`. The array store is tests-only. Resolving
`StandardWebhookVerifier` (or `SeenWebhookIdStore`) with a refused driver
throws `UnsupportedWebhookIdCacheStoreException` immediately — not a later
generic 500. Catch `DuplicateWebhookIdException`,
`WebhookIdStoreFailedException`, and `InvalidWebhookSignatureException`
instead of string-matching; messages stay stable for BC. The service
provider binds a cache-backed `SeenWebhookIdStore` by default.

`WebhookSecret::parseAllowingLegacyRaw()` accepts non-`whsec_` base64 only when
`outbound.webhooks.allow_raw_legacy_secret` is true. Secrets use
`#[\SensitiveParameter]` and are redacted in `__debugInfo` / string casts.
Do **not** `serialize` / `var_export` / `(array)` a `WebhookSecret` — persist a
reference (secret id) or the canonical `whsec_` string from `serialized()`.
(`var_export` / `(array)` dumps cannot be prevented by magic methods.)

Official vectors: `tests/Fixtures/StandardWebhooks/` (standard-webhooks
`@ 3d65b1824b6697f05a5a4584e7062bd23512fc8e`).

### Secret rotation

An endpoint signs with its **signing set**: the primary secret plus the
non-revoked secrets whose `expires_at` is still in the future, primary first,
at most `outbound.webhooks.max_secrets` (4). `SecretRotator` keeps exactly one
primary per endpoint, under a row lock on the endpoint:

```php
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;

$rotator = app(SecretRotator::class);

$secret = $rotator->issue($endpoint);             // first primary
$secret = $rotator->rotate($endpoint);            // old primary signs 24 h more
$secret = $rotator->rotate($endpoint, graceSeconds: 0); // leaked: old key stops now
$rotator->revoke($oldSecret);                     // a grace secret stops now

$secret->secret; // the whsec_ string: show it once, never again

$signingSet = app(WebhookSecretResolver::class)->signingSetFor($endpoint);
$signer->sign($msgId, $unixSeconds, $rawBody, $signingSet->secrets());
$signingSet->secretPublicIds(); // record which secrets signed, never their bytes
```

`max_secrets` is a hard limit of 1–4: a larger value is refused when the
application boots. An issue or a rotation that would push the set past it is
refused: revoke an old secret first. The primary cannot be revoked; rotate
with a zero grace period instead. A deleted endpoint gets no new secret, but
its old secrets can still be revoked. Grace periods are measured in UTC: the
package models store and read every date as UTC whatever `app.timezone` is,
so a daylight-saving change never stretches or cuts one. The resolver fails closed when an endpoint has no active
primary or more than one. Only `v1` rows sign; `v2a` rows are reserved. A row
flagged `is_legacy_raw` may hold a non-`whsec_` secret only while
`outbound.webhooks.allow_raw_legacy_secret` is on. A product that keeps its
secrets elsewhere binds its own `WebhookSecretResolver`.

### Retry schedule and delivery records

```php
use Qiick\Outbound\Delivery\DeliveryAttempt;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Retry\RetrySchedule;
use Qiick\Outbound\Retry\SystemRandomizer;

$delays = match (config('outbound.webhooks.retry_preset')) {
    'short' => RetrySchedule::shortDelays(),
    default => RetrySchedule::standardDelays(),
};
// Each delay is measured from the previous attempt, not from the first send.
$wait = RetrySchedule::withJitter(
    $delays[0],
    (int) config('outbound.webhooks.retry_jitter_seconds'),
    new SystemRandomizer,
);

$attempt = new DeliveryAttempt(
    eventId: $msgId,
    eventType: 'order.created',
    attempt: 1,
    status: DeliveryStatus::Failed,
    httpStatus: 503,
    errorMessage: 'upstream unavailable',
    at: new DateTimeImmutable,
    nextAttemptAt: new DateTimeImmutable('+'.$wait.' seconds'),
);
```

Prefer `standard` delays (5s, 5m, 30m, 2h, 5h, 10h, 14h, 20h, 24h — about
**75.6 hours** end-to-end); each entry is the wait **from the previous attempt**.
Bound `short` (`[10, 30, 60]`) to a migration window. Jitter is capped
(configured and ±floor(delay/2)) so a positive delay is never immediate.
`DeliveryAttempt` strips control characters and caps `errorMessage` length.
Until the delivery coordinator lands (the next PRs of the 1.0 work), products
still enforce per-endpoint concurrency, timeouts, circuit-break / auto-disable,
and honour `Retry-After` / 410 / 429: the tables already hold the columns
(`failing_since`, `consecutive_failures`, `disabled_reason`), but no package
code applies the auto-disable policy yet.

## Webhook tables

The package owns five tables with fixed, standard names (standards review
of 2026-11-22; the names are not configurable):

| Table | One row per |
| --- | --- |
| `webhook_endpoints` | destination URL, with its policy overrides and failure streak |
| `webhook_endpoint_secrets` | signing secret (up to 4 during a rotation), encrypted at rest |
| `webhook_subscriptions` | endpoint and event type (`*` for every type) |
| `webhook_deliveries` | endpoint and event, with the retry state and the encrypted body |
| `webhook_delivery_attempts` | HTTP attempt, append-only |

They are mandatory, so the service provider loads the migrations itself:
`php artisan migrate` creates them. A product that wants the files in its own
`database/migrations` publishes them and turns loading off:

```bash
php artisan vendor:publish --tag=outbound-migrations
OUTBOUND_MIGRATIONS_LOAD=false
```

> **Do both or neither.** Publishing with loading still on registers each
> table twice: `migrate` then fails on the second `create` with "table already
> exists". Set `OUTBOUND_MIGRATIONS_LOAD=false` in every environment (and in
> `.env.example`) in the same change that commits the published files.

Eloquent models live in `Qiick\Outbound\Models` (`WebhookEndpoint`,
`WebhookEndpointSecret`, `WebhookSubscription`, `WebhookDelivery`,
`WebhookDeliveryAttempt`). Each has a UUID v7 `public_id`, which is its route
key and the only id that leaves the database; the bigint `id`, foreign keys,
`secret`, `payload` and the endpoint's custom `headers` (often a token) are
hidden from arrays and JSON. Those three use Laravel's `encrypted` cast, so
rotating `APP_KEY` needs `APP_PREVIOUS_KEYS`. `payload_hash` (the plain SHA-256 of the body, to
deduplicate and verify) is hidden too, and a failed delivery save throws
`WebhookDeliveryStorageException` (or `DuplicateWebhookDeliveryException`)
with only the SQLSTATE, never Laravel's `QueryException` with its bindings. `created_by` and `triggered_by` reference
`outbound.webhooks.users_table` (`users`) with null-on-delete.

**Scope column.** The package defines no tenancy column and no morph. A product
that scopes endpoints adds its own foreign key to `webhook_endpoints` with its
own migration, indexed with `is_active`, and names it in
`outbound.webhooks.scope_column`:

```php
Schema::table('webhook_endpoints', function (Blueprint $table): void {
    $table->foreignId('project_id')->nullable()->after('public_id')->constrained()->cascadeOnDelete();
    $table->index(['project_id', 'is_active']);
});
// .env: OUTBOUND_WEBHOOK_SCOPE_COLUMN=project_id
```

Null (the default) means one instance-wide scope. A product may add other
columns the same way; the package reads only the scope column.

Before `1.0.0` a schema change edits the package's `create_` migrations
instead of adding new ones: recreate the database (`migrate:fresh`) after an
upgrade. From `1.0.0` the migrations are frozen and change only additively.

## Configuration

| Config key | Env | Default |
| --- | --- | --- |
| `outbound.migrations.load` | `OUTBOUND_MIGRATIONS_LOAD` | `true` |
| `outbound.ssrf.allow_private_hosts` | `OUTBOUND_ALLOW_PRIVATE_HOSTS` | `false` |
| `outbound.ssrf.https_only` | `OUTBOUND_HTTPS_ONLY` | `false` |
| `outbound.http.timeout_seconds` | `OUTBOUND_TIMEOUT_SECONDS` | `5` |
| `outbound.http.connect_timeout_seconds` | `OUTBOUND_CONNECT_TIMEOUT_SECONDS` | `3` |
| `outbound.http.max_timeout_seconds` | `OUTBOUND_MAX_TIMEOUT_SECONDS` | `60` |
| `outbound.http.max_connect_timeout_seconds` | `OUTBOUND_MAX_CONNECT_TIMEOUT_SECONDS` | `30` |
| `outbound.http.total_deadline_seconds` | `OUTBOUND_TOTAL_DEADLINE_SECONDS` | `30` |
| `outbound.http.max_response_bytes` | `OUTBOUND_MAX_RESPONSE_BYTES` | `1048576` |
| `outbound.http.max_redirects` | `OUTBOUND_MAX_REDIRECTS` | `0` |
| `outbound.webhooks.replay_tolerance_seconds` | `OUTBOUND_WEBHOOK_TOLERANCE_SECONDS` | `300` |
| `outbound.webhooks.max_payload_bytes` | `OUTBOUND_WEBHOOK_MAX_PAYLOAD_BYTES` | `1048576` |
| `outbound.webhooks.max_signatures` | `OUTBOUND_WEBHOOK_MAX_SIGNATURES` | `8` |
| `outbound.webhooks.max_secrets` | `OUTBOUND_WEBHOOK_MAX_SECRETS` | `4` |
| `outbound.webhooks.secret_rotation_grace_seconds` | `OUTBOUND_WEBHOOK_SECRET_ROTATION_GRACE_SECONDS` | `86400` |
| `outbound.webhooks.retry_preset` | `OUTBOUND_WEBHOOK_RETRY_PRESET` | `standard` |
| `outbound.webhooks.retry_jitter_seconds` | `OUTBOUND_WEBHOOK_RETRY_JITTER` | `30` |
| `outbound.webhooks.allow_raw_legacy_secret` | `OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET` | `false` |
| `outbound.webhooks.scope_column` | `OUTBOUND_WEBHOOK_SCOPE_COLUMN` | `null` |
| `outbound.webhooks.users_table` | `OUTBOUND_WEBHOOK_USERS_TABLE` | `users` |

## Development

```bash
composer update
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan level max
```

Mutation (CI): `bash scripts/mutate-per-class.sh` — each listed security class
must score ≥95% (`RemoteUrlGuard`, `AuthorizedDestination`, `PinnedHttpClient`,
`WebhookSecret`, `StandardWebhookSigner`, `StandardWebhookVerifier`,
`CacheSeenWebhookIdStore`, `RetrySchedule`), and every class added for the
delivery layer, without `--covered-only`. No method-level
`@pest-mutate-ignore`.

The suite runs on in-memory SQLite by default; CI also runs it on PostgreSQL,
MySQL and MariaDB. Point it at a server with the usual `DB_*` variables
(`DB_CONNECTION=pgsql DB_DATABASE=laravel_outbound vendor/bin/pest`).

Tests are deterministic: no live network. DNS and HTTP use injectable fakes;
clock and randomizer are fixed in tests.

## Licence

MIT. See [LICENSE](LICENSE).

## Security

See [SECURITY.md](SECURITY.md). Use GitHub private vulnerability reporting; do
not open a public issue for security findings.
