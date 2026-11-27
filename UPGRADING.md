# Upgrading

This package has not published a stable release yet. Until `1.0.0`, treat every
public surface as unstable: read the release's section of `CHANGELOG.md` and
the approved design before upgrading, and prefer a tagged release to a commit
from `develop`.

## From unreleased commits

- Prefer depending on a tagged release once one exists; do not pin `dev-develop`
  in production products.
- Config keys use the `outbound.*` / `OUTBOUND_*` names. Products that still
  expose legacy `WEBHOOKS_*` env vars should map them at the application layer;
  this package does not read `WEBHOOKS_*`.
- After `0.1.0`, `qiick/laravel-oidc` is expected to depend on this package and
  delete its local `RemoteUrlGuardService`. Until then, shared SSRF fixtures are
  copied so blocklists cannot drift silently.

## Unreleased (erasure)

- `webhook_deliveries.subject_public_id` (uuid, nullable, indexed) and
  `claimed_triggered_by` (nullable, indexed, foreign key to the users table
  like `next_attempt_triggered_by`) are new in the `create_` migration, and
  `payload_hash` is nullable (pre-1.0): recreate the database, or add the
  columns and their indexes and alter `payload_hash`.
- `outbound.webhooks.payload_retention_days` above 30 now fails the boot.
  Deliveries dispatched without `subjectPublicId` keep their body for the
  retention only: a retry plan longer than that is cut short.
- Pass `subjectPublicId:` to `dispatch()` for every event about a person, and
  call `OutboundEraser` from the product's erasure orchestrator only (README,
  "Erasure"). A delivery queued without it keeps its encrypted payload until
  it expires.
- **Commands renamed** to the fleet form `outbound:<verb>-<object>` (F6):
  `outbound:webhooks:work` is `outbound:work-deliveries` and
  `outbound:webhooks:prune` is `outbound:prune-deliveries`. Update the
  product's schedule (`routes/console.php`) and any deploy script. The old
  names remain hidden for one beta and log a deprecation on every use.
  `outbound:check` fails if a schedule still uses either old name.
- **The package schedules `outbound:prune-deliveries` itself** (daily,
  `onOneServer()`, `withoutOverlapping()`): remove the product's own
  `Schedule::command(...prune...)` line, or set `OUTBOUND_WEBHOOK_SCHEDULE=false`
  to keep it. A published config gets the new `outbound.webhooks.schedule`
  key from the package default.
- **The owner email listener is registered only while
  `outbound.webhooks.auto_disable.notify_owner` is on** at boot (F3).
- Add `php artisan outbound:check` to the deploy (F2).

### Product adoption checks

- Update the tagged Outbound dependency and the product's Composer lockfile.
  A product that also installs OIDC must use an OIDC constraint that allows
  the chosen Outbound tag. OIDC `1.0.0-beta.8` allows Outbound `0.1` or
  `0.2`.
- Recreate the prerelease webhook schema on a disposable database and verify
  the five package tables. If preserving beta deliveries, apply the column
  and index changes listed above before starting a worker on the new code.
- Schedule `outbound:work-deliveries`. Keep the package's daily prune, or
  turn it off with `OUTBOUND_WEBHOOK_SCHEDULE=false` and schedule
  `outbound:prune-deliveries` yourself if the product needs its own timing
  or post-prune hook. Run `outbound:check` after migration and config caching.
- Pass the subject's public UUID as `subjectPublicId:` for each delivery
  about a person. Add `OutboundEraser` to the product's resumable erasure
  orchestrator before it deletes the user, and cover cancellation and body
  removal in the product's C17 test.
- An inbound consumer passes a stable non-empty tenant-and-sender scope to
  `StandardWebhookVerifier::verify()`. When using the claim store directly,
  keep the winning `WebhookIdClaim` for release if processing must be retried.

## To 0.2.0-beta.3 (endpoint private hosts)

- `webhook_endpoints.allow_private_hosts` is `NOT NULL DEFAULT false` (the
  `create_` migration changed, pre-1.0): recreate the database, or set
  `allow_private_hosts = false` where it is null and alter the column. An
  endpoint left at null used to inherit the global setting; it now stays off
  private hosts until the product sets it to `true` (with `forceFill()`),
  which counts only while `ssrf.allow_private_hosts` allows them.

## To 0.2.0-beta.2 (adoption fixes)

Read the first item before upgrading: it changes which destinations can be
reached.

- **Private hosts: the global setting is now the ceiling.**
  `ssrf.allow_private_hosts` (`OUTBOUND_ALLOW_PRIVATE_HOSTS`) decides whether
  private, loopback and link-local addresses can be reached at all; a call or
  an endpoint can only narrow it.
  - `UrlGuardOptions(allowPrivateHosts: true)` no longer opens private hosts
    on a guard that refuses them. Pass `null` (the new default) to inherit
    the guard's setting, or `false` to refuse private hosts for one call.
  - `outbound.webhooks.allow_endpoint_private_hosts`
    (`OUTBOUND_WEBHOOK_ALLOW_ENDPOINT_PRIVATE_HOSTS`) is removed. An
    endpoint's `allow_private_hosts` now reads: null inherits the global
    setting, `false` refuses private hosts for that endpoint, `true` never
    allows what the global setting refuses.
  - A product that let a few endpoints reach internal hosts with the global
    setting off now turns `ssrf.allow_private_hosts` on and sets
    `allow_private_hosts = false` (with `forceFill()`) on every endpoint that
    must stay public; with the global setting on, an endpoint left at null
    reaches private hosts.
- The `create_` migrations index three foreign keys (pre-1.0, so they
  changed). A database migrated with `0.2.0-beta.1` runs `migrate:fresh`, or
  adds the indexes once (MySQL and MariaDB already have them from the
  foreign keys):
  `CREATE INDEX webhook_endpoints_created_by_index ON webhook_endpoints (created_by)`,
  `CREATE INDEX webhook_deliveries_next_attempt_triggered_by_index ON webhook_deliveries (next_attempt_triggered_by)`,
  `CREATE INDEX webhook_delivery_attempts_triggered_by_index ON webhook_delivery_attempts (triggered_by)`.

## To 0.2.0-beta.1 (webhook delivery)

- Run `php artisan migrate`: the package now loads the migrations of five
  tables (`webhook_endpoints`, `webhook_endpoint_secrets`,
  `webhook_subscriptions`, `webhook_deliveries`, `webhook_delivery_attempts`).
  Their names are fixed; an unreleased product with its own tables of the same
  names drops or renames them and recreates its schema on the package tables.
- Do not publish the migrations unless you also set
  `OUTBOUND_MIGRATIONS_LOAD=false` everywhere: with both, `migrate` fails on
  the second `create` of each table.
- Mass assignment takes an explicit allow-list per model. Set a secret with
  `WebhookEndpointSecret::useSecret()` and a body with
  `WebhookDelivery::usePayload()`; lifecycle and retry columns are written by
  the package. `Model::unguard()` / `Model::unguarded()` (common in seeders)
  turns the allow-lists off: seed secrets with `SecretRotator`, never with
  `create([... 'is_primary' => true])`, or the endpoint ends up with two
  primaries and stops signing. A secret cannot be `replicate()`d.
- `webhook_endpoints.headers` is now `text` under the `encrypted:array` cast,
  and hidden from arrays and JSON: recreate the database. Like `secret` and
  `payload`, it is unreadable after an `APP_KEY` rotation unless the old key
  is in `APP_PREVIOUS_KEYS`.
- Schedule `outbound:webhooks:work` (`outbound:work-deliveries` since the F6
  rename above, for example `everyFiveSeconds()`) and dispatch events with
  `WebhookDeliveryCoordinator::dispatch()` instead of a product job. Webhook
  POSTs now time out after 15 s
  (`OUTBOUND_WEBHOOK_TIMEOUT_SECONDS`): a scheduler or queue timeout around
  the command must be longer. `webhook_deliveries` gains `claimed_until` and
  `exhausted_reason`: recreate the database.
- Auto-disable is on by default (410 at once; otherwise 5 days without a
  success and at least 10 consecutive failures), and the endpoint's
  `created_by` user is emailed through the application's user model, which
  must use `Notifiable`. Set `OUTBOUND_WEBHOOK_AUTO_DISABLE=false` or
  `OUTBOUND_WEBHOOK_AUTO_DISABLE_NOTIFY_OWNER=false` to opt out, and offer
  users a way to re-enable an endpoint (`enableEndpoint()`, the only
  supported way). `created_by` and `auto_disable` are no longer
  mass-assignable: set them in your own code (`created_by` from
  `auth()->id()`), never from request input, since the disable email goes
  to `created_by`.
- `webhook_deliveries` gains `next_attempt_trigger` and
  `next_attempt_triggered_by` (redelivery): recreate the database. A
  redelivery keeps its `webhook-id`, so a consumer that claimed the id and
  then failed drops it as a duplicate until the claim expires.
- Schedule `outbound:webhooks:prune` (`outbound:prune-deliveries` since the
  F6 rename above, for example `daily()`). Bodies are kept 30 days after
  dispatch (`OUTBOUND_WEBHOOK_PAYLOAD_RETENTION_DAYS`), or until their retry
  plan ends if later, then cleared. Settled delivery rows are deleted after
  90 days (`OUTBOUND_WEBHOOK_DELIVERY_RETENTION_DAYS`).
  `WebhookExhaustedReasonEnum` gains `PayloadExpired`: add it to any
  exhaustive `match`.
- `SeenWebhookIdStore` gains `release()`: a custom implementation must add
  it. Webhook ids with a `.` and event types outside dot-separated
  `[A-Za-z0-9_]` segments (for example `invoice-paid`) are now refused.
- `SeenWebhookIdStore::claim()` takes a `WebhookIdClaim` instead of the
  scope and id, and `release()` takes that claim: a custom store implements
  `claim(WebhookIdClaim $claim, int $ttlSeconds): bool`, stores
  `$claim->token`, and releases only while it still holds that token. A
  direct caller builds the claim with `WebhookIdClaim::generate($scope,
  $webhookId)`. `StandardWebhookVerifier::verify()` returns the claim it won
  (null without a store); keep it to release the id when processing fails.
  Event types are at most 191 bytes, and webhook ids must be valid UTF-8.
- The minimum Guzzle on the 7 line is 7.12 (`^7.12 || ^8.0`): run
  `composer update guzzlehttp/guzzle` if the application pins an older 7.x.
- `webhook_deliveries.payload_expires_at` is required: a product that
  inserts delivery rows itself (seeders, imports) sets it, or the insert
  fails. Recreate the database.
- Replace `DeliveryAttempt::MAX_ERROR_MESSAGE_BYTES` with
  `DeliveryAttempt::maxErrorMessageBytes()`. Check a published
  `config/outbound.php`: a value of the wrong type (a quoted number, `'yes'`
  for a boolean) or an unknown `retry_preset` now stops the boot with the
  key's name. Pass `asMultipart()` a list of parts.
- Replace `RetrySchedule::MAX_DELAY_SECONDS` with
  `RetrySchedule::maxDelaySeconds()`; `MAX_JITTER_SECONDS` is gone (one hour,
  internal). `RemoteUrlGuard` now refuses host names with characters other
  than letters, digits, hyphens, underscores and dots.
- Pass `dedupeScope` to every `StandardWebhookVerifier::verify()` call (the
  secret's or endpoint's id); it no longer defaults to `'default'`. Set the
  scope column of a `WebhookEndpoint` with `forceFill()`: `fill()` and
  `create()` ignore it now.
- `webhook_endpoints` gains `rate_window_started_at`, `rate_window_attempts`
  and `throttled_at` (the throttle's state): recreate the database. An
  endpoint's `rate_limit_per_minute` now caps its attempts per minute (null
  or 0: no limit), and is no longer mass-assignable: set it with
  `forceFill()`.
- A product that scopes endpoints adds its scope foreign key to
  `webhook_endpoints` with its own migration and sets
  `OUTBOUND_WEBHOOK_SCOPE_COLUMN` (see the README, "Webhook tables").
- Add the new `DeliveryStatus` cases (`Pending`, `Exhausted`, `Cancelled`) to
  any exhaustive `match`.
- Until `1.0.0` the package edits its `create_` migrations instead of adding
  new ones, so recreate the database after each pre-1.0 upgrade.

## To 0.1.1 (webhook id stores)

- Catch the typed webhook exceptions (`DuplicateWebhookIdException`,
  `WebhookIdStoreFailedException`, `InvalidWebhookSignatureException`)
  instead of matching messages; the messages are unchanged.
- The webhook id cache must be database, redis, memcached, dynamodb or file
  (single host). `storage` and `failover` are refused and array is for tests
  only: resolving `StandardWebhookVerifier` with a refused store throws
  `UnsupportedWebhookIdCacheStoreException`.

## To 0.1.0 (first release)

- `composer require qiick/laravel-outbound`, publish `outbound-config` if the
  defaults need changing, and replace the product's own URL guard with
  `RemoteUrlGuard` (README, "SSRF").

## Deprecation windows (products)

- Externa legacy `X-Externa-*` webhook headers: dual-send with Standard Webhooks
  for **6 months or two minor releases, whichever is longer** (core-hardening
  decision, 2026-11-20). Pair with `OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET`
  until secrets are `whsec_`.
