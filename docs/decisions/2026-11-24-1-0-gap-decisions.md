# 2026-11-24: decisions on the 1.0 gaps

Decided by the Team Leader bot on 2026-11-24, under the maintainer's rule "standard and safe", from
the comparison of this package with the qiick packages 1.0 checklist. The rule applied:
anything that would change behaviour, the public API or the schema if added later lands
before 1.0; a product-facing contract is decided now, not after the freeze.

| # | Item | Decision | Why |
| --- | --- | --- | --- |
| D1 | S7 per-endpoint throttle (`rate_limit_per_minute`) and halved concurrency after 429, 502, 503 and 504 | **Before 1.0** (confirmed by the maintainer on 2026-11-24) | Turning it on later changes the delivery rhythm of every product: a behaviour change, so it lands before the freeze. The column already exists. |
| D2 | S8 `LegacyWebhookProfile` (a hook for externa's legacy headers and body keys) | **Before 1.0** | externa adopts last and is the only product that needs it; without it externa 1.6 would wait for a package minor. |
| D3 | Mutation of the `Testing\Fake*` classes | **Yes**, like every class in `src/` | They are `@api`: products build their own tests on them, so a wrong fake hides a product bug. |
| D4 | Redis in CI for the webhook-id claim concurrency | **Yes** | Redis is the store products use in production and the README recommends; the claim's atomicity is tested on it with truly parallel processes. |
| D5 | Second product adopting the beta | **signaculum** | It has its own webhook tables, the case closest to the package schema, so the adoption tests the migration path too. excubia stays the first. |
| D6 | `@internal` classes | **`DatabaseDeliveryStore`, `EndpointDisablePolicy`, `AuthorizedDestination`, `NotifyWebhookEndpointOwnerListener` and the console command classes are `@internal`**; the command names (`outbound:webhooks:work`, `outbound:webhooks:prune`) stay public | Products go through `WebhookDeliveryCoordinator`, `SeenWebhookIdStore`, the events and the commands; the store and policy are implementation. |
| D7 | Shared CI matrix (checklist 17e) | **A reusable `package-matrix.yml` in app-ui**, adopted by every package together; no interim `php-quality.yml` here | app-ui has no package matrix today: `php-quality.yml` is one PHP version and one job, and cannot carry the PHP × Laravel × lowest/stable matrix or this package's real-transport, mutation and database jobs. |
| D8 | Required checks on `main` | **The same as on `develop`**, set by the maintainer after the next merge to `main` | Today `main` requires none; a release must pass the same gate as integration. |
| — | S4 `MetadataPolicy` | **Before 1.0** | A security contract (extending the always-blocked hosts); adding it after the freeze would change the guard's API. |
| — | S5 `WebhookDeliveryFailed` event | **Before 1.0** | The other two S5 events exist; an exhausted delivery is the case a product most needs to hear about. Public ids and the reason only. |
| — | S6 guidance never to echo raw responses | **Before 1.0** (README) | `response_excerpt` already stays empty; the guidance is documentation only. |
| — | L1–L6 | **After 1.0, additive** | Their columns are reserved (`scheme`, `public_key`, `key_ref`, `verified_at`, `filter`); the 1.0 release notes list them. |
