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
