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

## Deprecation windows (products)

- Externa legacy `X-Externa-*` webhook headers: dual-send with Standard Webhooks
  for **6 months or two minor releases, whichever is longer** (core-hardening
  decision, 2026-11-20). Pair with `OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET`
  until secrets are `whsec_`.
