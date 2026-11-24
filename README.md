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
- **Webhook delivery** — fan-out to subscribed endpoints, signed attempts
  through the pinned client, the Standard Webhooks retry schedule with
  `Retry-After`, and per-endpoint concurrency (see
  [Webhook delivery](#webhook-delivery)).

Non-goals for v1: asymmetric Standard Webhooks (`v2a`; its columns are
reserved), global helpers, a hard dependency on `laravel/framework`. The
rest of the delivery layer the 2026-11-22 standards review made mandatory
before 1.0 (the egress proxy) lands in a following pull request.

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

`WebhookDeliveryCoordinator` enforces per-endpoint concurrency, the webhook
timeout, `Retry-After`, 410 and auto-disable after repeated failures.

## Requirements

- PHP ^8.3 with `ext-curl`, `ext-intl`, and `ext-mbstring`
- illuminate/contracts, illuminate/http, illuminate/support ^12 or ^13
- guzzlehttp/guzzle ^7.12 or ^8.0

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

A transport failure throws `OutboundHttpException` with a fixed message and
`transportErrorKind()`: `timeout` (curl or the chain deadline), `tls`, `dns`
or `connection`, read from the curl errno. Neither the message nor the
exception chain names the host, the pinned address or the URL. Refusals the
client makes itself (response size, redirect rules) have no kind.

**Egress proxy.** Set `outbound.http.egress_proxy` (`OUTBOUND_EGRESS_PROXY`,
for example `http://user:secret@egress.internal:3128`) to send every request
through a filtering forward proxy, as Standard Webhooks "Server side request
forgery (SSRF)" recommends. It is deployment config, never set per endpoint
or per call; null (the default) sends directly, and the `HTTP(S)_PROXY` and
`NO_PROXY` environment variables are never read either way (Guzzle 7.12 is
the first 7.x that guarantees this, hence the minimum). Any value other than
an http(s) proxy URL, null or an empty string stops the boot.

- The guard still authorises and pins every destination, so the proxy adds a
  network-layer control without replacing the application one. curl is
  forced into tunnel mode, also for plain `http://`: the proxy receives
  `CONNECT <guard-approved address>:<port>`, never a URL or a host name to
  resolve, so it cannot be steered to an internal address the guard refused.
  A refused destination never reaches the proxy.
- Only `http://` and `https://` proxies with a host and nothing after the
  port are accepted, checked at boot; a SOCKS proxy could resolve names
  itself. The proxy's own address is trusted infrastructure, not checked by
  the guard.
- `EgressProxy` keeps the credentials out of string casts, dumps (`dump()`,
  `dd()`, `var_export()`), serialization, exception messages and exception
  traces; a proxy refusal surfaces as a transport error with the fixed
  messages above.


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
$claim = $verifier->verify(
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

`verify()` returns the `WebhookIdClaim` it won (null without a store). A
consumer whose processing fails after that gives it back with
`SeenWebhookIdStore::release($claim)`, so the sender's retry or redelivery
(same `webhook-id`) is processed instead of being dropped as a duplicate.
Only the winning claim releases: a duplicate gets no claim, and a claim
another request holds carries a different random token, so it is left in
place. A store that cannot release fails closed
(`WebhookIdStoreFailedException`).

The signer and the verifier refuse a `webhook-id` that is empty, holds a `.`
(it separates the signed fields) or a control character. `WebhookMessage`
checks the same at construction, plus at most 191 bytes of valid UTF-8 (the
stored `event_id`), and requires the event type to be at most 191 bytes of
dot-separated segments of letters, digits and underscores (`invoice.paid`,
Standard Webhooks "Event types").

Two events carry no secret and no body: `WebhookSignedEvent` (webhook id,
timestamp, signature count, body length) after each signing, and
`OutboundRequestBlockedEvent` (host and the guard's reason, never the path
or query) when `PinnedHttpClient`'s guard refuses a destination, for
alerting on attempts to reach internal addresses.

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

### Webhook delivery

```php
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Webhooks\WebhookMessage;

app(WebhookDeliveryCoordinator::class)->dispatch(
    new WebhookMessage('msg_'.Str::ulid(), 'invoice.paid', now()->toDateTimeImmutable(), ['invoice' => $invoice->public_id]),
    scope: $project->public_id, // the product's scope column value; omit for instance scope
);

// routes/console.php
Schedule::command('outbound:webhooks:work')->everyFiveSeconds();
```

`dispatch()` stores one pending delivery per active endpoint subscribed to the
type (or to `*`), with the exact body every attempt signs; dispatching the same
message again creates nothing. `outbound:webhooks:work` takes up to `--limit`
(50) due deliveries, leases each one on its own right before its send, signs
each attempt with the endpoint's signing set
and a fresh `webhook-timestamp`, and POSTs it through `PinnedHttpClient`
only: SSRF guard, pinned address, no proxy, never a redirect. Retries are
driven by `next_attempt_at`, so the first retry is as punctual as the
schedule above, and a retry 24 h away survives a queue loss.

- **Timeout:** `outbound.webhooks.timeout_seconds` (15 s, Standard Webhooks
  "Request timeouts"); an endpoint's `timeout_seconds` overrides it, never
  above `http.max_timeout_seconds`, and the 30 s chain deadline still applies.
  Other outbound calls keep `http.timeout_seconds` (5 s).
- **Failures:** every non-2xx answer (3xx included: redirects are never
  followed), timeout and connection error is retried on the schedule: the
  endpoint's `retry_preset` or the configured one, `max_attempts` (10 for
  `standard`: 75 h 35 min) snapshotted at dispatch. A guard or signer refusal
  is recorded as `refused` and retried the same way, and so is an endpoint row
  the request cannot be built from (`internal_error`). 410 Gone stops the
  delivery at once (`exhausted`, `gone`) and disables the endpoint (see
  [Auto-disable](#auto-disable)).
- **Retry-After** (429, 502, 503, 504): jitter is applied to the schedule
  first, then the retry never comes before the header's delay, plus up to
  min(jitter, delay / 2). A delay above `retry_after_cap_seconds` (24 h) stops
  automatic retries (`exhausted`, `retry_after_exceeds_cap`) instead of being
  shortened; values above 30 days read as 30 days, so a cap above that is
  never reached. Only the first `Retry-After` counts. All three HTTP-date
  formats are read (`RetryAfter::parse()`).
- **Concurrency and fairness:** at most the endpoint's `max_concurrency`
  (`default_max_concurrency`, 2) attempts in flight per endpoint, across
  workers. Each delivery is leased atomically just before its send, for the
  chain deadline plus a minute, so overlapping runs never send one twice, and
  a crashed worker's lease simply expires. A run takes at most each
  endpoint's concurrency from its backlog, so one busy endpoint (or tenant)
  cannot starve the others.
- **Private hosts:** an endpoint's `allow_private_hosts` loosens the SSRF
  guard, so it is not mass-assignable and counts only when the product sets
  `outbound.webhooks.allow_endpoint_private_hosts`; otherwise only
  `ssrf.allow_private_hosts` decides, for every endpoint.
- **Records:** each attempt row keeps the status, HTTP status, error code
  (`http_status`, `gone`, `timeout`, `tls`, `dns`, `connection`, `transport`
  for a client refusal without a transport kind such as a response over
  `max_response_bytes`, `ssrf_refused`, `signing`, `request_refused`,
  `internal_error`), sanitised message, the secrets that
  signed, duration, `Retry-After`, and only the `content-type`,
  `retry-after`, `x-request-id` and `date` response headers, as valid UTF-8
  without control characters, at most 255 characters each; `request_id` keeps
  only `A-Z a-z 0-9 . _ : -`, at most 64. The response body is never stored.
  If the database still refuses the receiver's text, the attempt is stored
  without it: a delivered webhook is never sent again because of it. The
  endpoint's failure streak (`consecutive_failures`, `failing_since`,
  `last_success_at`) moves with every attempt.

### Auto-disable

On by default (standards review R8): an endpoint that answers **410 Gone** is
disabled at once; otherwise it is disabled after **5 days without a
successful attempt and at least 10 failed attempts in a row**. Five days is
longer than one full 75 h schedule, so at least one delivery has used every
retry first. Thresholds below 1 are refused when the application boots. An
endpoint's `auto_disable` (null = inherit) overrides
`outbound.webhooks.auto_disable.enabled`; like `created_by`, it is not
mass-assignable, so only the product's own code sets it, never a request.
Only the receiver's failures count (an HTTP error or a transport error): a
refusal on the package's side (signing, the SSRF guard, an internal error)
leaves the streak as it is. A success another worker records while a
disable is being decided cancels it.

A disabled endpoint gets no new deliveries and its pending ones wait. Each
disable fires `WebhookEndpointDisabledEvent` (public id, reason, time) once,
so a product can add its own channels. The package also emails the
endpoint's `created_by` user, loaded with `auth.providers.users.model`
(`WebhookEndpointDisabledNotification`, queued), for automatic disables only;
turn that off with `outbound.webhooks.auto_disable.notify_owner`. The mail
carries no text a tenant wrote: a free-form name could hold a bare URL that
mail clients turn into a link. It names the endpoint by its host (a valid
host name or IP literal, else "an unknown host") and its public id, never
its URL, and its only link is the product's own endpoint page,
`outbound.webhooks.auto_disable.endpoint_url` with `{endpoint}` replaced by
the public id (no link when unset).

```php
$coordinator = app(WebhookDeliveryCoordinator::class);

$coordinator->disableEndpoint($endpoint); // reason "manual", no email
$coordinator->enableEndpoint($endpoint);  // clean failure streak; pending deliveries resume
```

`enableEndpoint()` is the only supported way back: setting `is_active` by
hand leaves `disabled_at`, `disabled_reason` and the failure streak behind.

### Redelivery

```php
$coordinator = app(WebhookDeliveryCoordinator::class);

$coordinator->redeliver($delivery, triggeredBy: auth()->id());
$coordinator->redeliverRange($endpoint, $from, $until, [DeliveryStatus::Exhausted], auth()->id()); // "Redeliver failed since…"
```

A redelivery is sent by the next `outbound:webhooks:work` run with the **same
`webhook-id`** (the delivery's event id), a fresh `webhook-timestamp` and fresh
signatures (standards review R7; Standard Webhooks "Webhook metadata" keeps the
id across retries). A consumer that already processed it deduplicates it. A
consumer that verifies with this package and fails after claiming the id
drops the redelivery as a duplicate until the claim expires;
`SeenWebhookIdStore::release()` for giving a claim back arrives in a later
pull request (review M14). The attempt records `trigger = manual|bulk` and `triggered_by`.

- A settled delivery (delivered, exhausted, refused, cancelled) gets exactly
  one more attempt: if it fails, it is exhausted again. A pending one is
  sent now and keeps its schedule.
- `redeliverRange()` takes the endpoint's deliveries created in
  [`$from`, `$until`) with the given statuses; deliveries whose body has been
  pruned are skipped. A large range does not starve other endpoints.
- Both refuse a disabled or deleted endpoint (enable it first) and a
  cancelled delivery: the product withdrew it, for good. A pruned body
  differs: `redeliver()` refuses its one delivery, while `redeliverRange()`
  skips pruned deliveries and schedules the rest, so one old delivery does not
  block a range. Both fire `WebhookRedeliveryRequestedEvent` (endpoint public
  id, count, trigger, user) for a product's audit log.
- Asking twice before the worker runs sends the delivery once, for the latest
  request. A request made while the delivery is being sent is kept, even from
  the same user (each request is counted): that attempt is recorded as it
  went, then the delivery is sent again.
- A redelivery goes to the endpoint's **current** URL (through the SSRF guard)
  with the current signing secrets, whatever the subscriptions are now:
  allow it only to users who may edit the endpoint. Redelivering a pending
  delivery sends it now, before any `Retry-After` the receiver asked for.
- `exhausted_reason` is cleared when a redelivery is scheduled; `delivered_at`
  keeps the time of the last successful delivery until a new one replaces it.

### Body retention

```php
// routes/console.php
Schedule::command('outbound:webhooks:prune')->daily();
```

A delivery's body is the exact payload every attempt signs, encrypted at rest
(`encrypted` cast). It is kept until `payload_expires_at`: 30 days after
dispatch by default (`outbound.webhooks.payload_retention_days`, at least 1;
the maintainer's decision Q3 of 2026-11-22), or until the delivery's longest
possible retry plan ends, plus an hour, if that is later. The plan counts
every retry at its delay plus the most jitter, or at the `Retry-After` cap
when Retry-After is honoured, so a receiver that keeps answering `503` with
a long `Retry-After`, or an endpoint with a higher `max_attempts`, never loses
its body mid-schedule.

`outbound:webhooks:prune` then clears the body and keeps `payload_hash` and
`payload_bytes`, so the delivery log still proves what was sent. It also
deletes settled deliveries created `outbound.webhooks.delivery_retention_days`
ago (90, at least the payload retention), with their attempts, once their
body has expired too; pending ones are kept. It works in blocks of 500, each in its own short transaction.

- A body that has expired, pruned or not, cannot be redelivered.
- A pending delivery whose body expires (an endpoint disabled for weeks) is
  exhausted (`payload_expired`); one a worker is sending keeps its body until
  its lease ends.

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
These pieces are what `WebhookDeliveryCoordinator` uses; call them directly
only for a delivery loop of your own.

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
deduplicate and verify) is hidden too. A failed `WebhookDelivery::save()`
throws `WebhookDeliveryStorageException` (`DuplicateWebhookDeliveryException`
for a unique key) with only the SQLSTATE, and a deadlock or serialization
failure throws `WebhookDeliveryConcurrencyException` (a `DeadlockException`,
so `DB::transaction(..., attempts: n)` still retries it), never Laravel's
exception with its bindings. Query recorders (Telescope, Debugbar, Flare with
query bindings, `DB::listen` loggers) still see the hash of every write: a
short, guessable body can be found from it. `created_by` and `triggered_by` reference
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
| `outbound.http.egress_proxy` | `OUTBOUND_EGRESS_PROXY` | `null` |
| `outbound.webhooks.replay_tolerance_seconds` | `OUTBOUND_WEBHOOK_TOLERANCE_SECONDS` | `300` |
| `outbound.webhooks.max_payload_bytes` | `OUTBOUND_WEBHOOK_MAX_PAYLOAD_BYTES` | `1048576` |
| `outbound.webhooks.max_signatures` | `OUTBOUND_WEBHOOK_MAX_SIGNATURES` | `8` |
| `outbound.webhooks.max_secrets` | `OUTBOUND_WEBHOOK_MAX_SECRETS` | `4` |
| `outbound.webhooks.secret_rotation_grace_seconds` | `OUTBOUND_WEBHOOK_SECRET_ROTATION_GRACE_SECONDS` | `86400` |
| `outbound.webhooks.retry_preset` | `OUTBOUND_WEBHOOK_RETRY_PRESET` | `standard` |
| `outbound.webhooks.retry_jitter_seconds` | `OUTBOUND_WEBHOOK_RETRY_JITTER` | `30` |
| `outbound.webhooks.timeout_seconds` | `OUTBOUND_WEBHOOK_TIMEOUT_SECONDS` | `15` |
| `outbound.webhooks.respect_retry_after` | `OUTBOUND_WEBHOOK_RESPECT_RETRY_AFTER` | `true` |
| `outbound.webhooks.retry_after_cap_seconds` | `OUTBOUND_WEBHOOK_RETRY_AFTER_CAP_SECONDS` | `86400` |
| `outbound.webhooks.default_max_concurrency` | `OUTBOUND_WEBHOOK_DEFAULT_MAX_CONCURRENCY` | `2` |
| `outbound.webhooks.allow_endpoint_private_hosts` | `OUTBOUND_WEBHOOK_ALLOW_ENDPOINT_PRIVATE_HOSTS` | `false` |
| `outbound.webhooks.payload_retention_days` | `OUTBOUND_WEBHOOK_PAYLOAD_RETENTION_DAYS` | `30` |
| `outbound.webhooks.delivery_retention_days` | `OUTBOUND_WEBHOOK_DELIVERY_RETENTION_DAYS` | `90` |
| `outbound.webhooks.auto_disable.enabled` | `OUTBOUND_WEBHOOK_AUTO_DISABLE` | `true` |
| `outbound.webhooks.auto_disable.no_success_days` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_NO_SUCCESS_DAYS` | `5` |
| `outbound.webhooks.auto_disable.min_consecutive_failures` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_MIN_CONSECUTIVE_FAILURES` | `10` |
| `outbound.webhooks.auto_disable.notify_owner` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_NOTIFY_OWNER` | `true` |
| `outbound.webhooks.auto_disable.endpoint_url` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_ENDPOINT_URL` | `null` |
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
