# Upgrading

This package has not published a stable release yet. Until `0.1.0`, treat every
public surface as unstable: read the `[Unreleased]` section of `CHANGELOG.md`
and the approved design before adopting a commit from `develop`.

## From unreleased commits

- Prefer depending on a tagged release once one exists; do not pin `dev-develop`
  in production products.
- Config keys use the `outbound.*` / `OUTBOUND_*` names. Products that still
  expose legacy `WEBHOOKS_*` env vars should map them at the application layer;
  this package does not read `WEBHOOKS_*`.
- After `0.1.0`, `qiick/laravel-oidc` is expected to depend on this package and
  delete its local `RemoteUrlGuardService`. Until then, shared SSRF fixtures are
  copied so blocklists cannot drift silently.

## To the next release (webhook tables)

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
- Schedule `outbound:webhooks:work` (for example `everyFiveSeconds()`) and
  dispatch events with `WebhookDeliveryCoordinator::dispatch()` instead of a
  product job. Webhook POSTs now time out after 15 s
  (`OUTBOUND_WEBHOOK_TIMEOUT_SECONDS`): a scheduler or queue timeout around
  the command must be longer. `webhook_deliveries` gains `claimed_until` and
  `exhausted_reason`: recreate the database.
- Auto-disable is on by default (410 at once; otherwise 5 days without a
  success and at least 10 consecutive failures), and the endpoint's
  `created_by` user is emailed through the application's user model, which
  must use `Notifiable`. Set `OUTBOUND_WEBHOOK_AUTO_DISABLE=false` or
  `OUTBOUND_WEBHOOK_AUTO_DISABLE_NOTIFY_OWNER=false` to opt out, and offer
  users a way to re-enable an endpoint (`enableEndpoint()`).
- A product that scopes endpoints adds its scope foreign key to
  `webhook_endpoints` with its own migration and sets
  `OUTBOUND_WEBHOOK_SCOPE_COLUMN` (see the README, "Webhook tables").
- Add the new `DeliveryStatus` cases (`Pending`, `Exhausted`, `Cancelled`) to
  any exhaustive `match`.
- Until `1.0.0` the package edits its `create_` migrations instead of adding
  new ones, so recreate the database after each pre-1.0 upgrade.

## Deprecation windows (products)

- Externa legacy `X-Externa-*` webhook headers: dual-send with Standard Webhooks
  for **6 months or two minor releases, whichever is longer** (core-hardening
  decision, 2026-11-20). Pair with `OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET`
  until secrets are `whsec_`.
