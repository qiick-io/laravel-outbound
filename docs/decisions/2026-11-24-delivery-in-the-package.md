# 2026-11-24: webhook delivery ships in the package (supersedes the design's v1 non-goals)

Recorded by the Team Leader bot on 2026-11-24, under the maintainer's rule "standard and safe". This is
the dated note the maintainer asked for in decision Q7 of the standards review (2026-11-22),
item M16 of that review.

## What it supersedes

The design of record (knowledge `projects/qiick/packages/laravel-outbound-design.md`,
2026-11-20) lists as **v1 non-goals**: "delivery tables/migrations; queue job bases /
auto-disable enforcement (documented as product duty)". Those non-goals are superseded in
part:

| Former non-goal | Now | Where |
| --- | --- | --- |
| Delivery tables and migrations | Five package migrations (`webhook_endpoints`, `webhook_endpoint_secrets`, `webhook_subscriptions`, `webhook_deliveries`, `webhook_delivery_attempts`), loaded by the package; names chosen by the package | standards review R1, M1, M2, M15 (#19, #21) |
| Delivery as a product duty | `WebhookDeliveryCoordinator` and `DatabaseDeliveryStore`: claims by `next_attempt_at`, per-endpoint concurrency, Standard Webhooks retries with `Retry-After`, manual and bulk redelivery with the same `webhook-id`, body retention | M5, M6, M9, M11, M12 (#24, #25, #35, #37) |
| Auto-disable enforcement as a product duty | `EndpointDisablePolicy`: 410 at once; otherwise 5 days and 10 failures; the owner is emailed | M10, R8, the maintainer's Q2 (#31) |

Still non-goals, unchanged: asymmetric signing `v2a` (LATER L1, columns reserved), global
helpers, a dependency on `laravel/framework`, and a package-shipped queue job base class
(LATER L5: the coordinator runs from the `outbound:webhooks:work` command instead).

The same change supersedes the line of knowledge `decisions/2026-11-20-fleet-contracts.md`
on product-owned delivery tables, which that note already marks as superseded on 2026-11-22.

## Why

The standards review (2026-11-22) found that every product had rebuilt the same delivery
code, each with its own gaps (fixed retry delays, no `Retry-After`, no auto-disable, lossy
logs), and that Standard Webhooks' "Deliverability and reliability" requirements cannot be
met by a signer alone. The maintainer accepted all of the review's recommendations the same day, with
the schema as package migrations (R1).

## Follow-up

The design note gets a "superseded in part by docs/decisions/2026-11-24-delivery-in-the-package.md"
line at the 1.0 release, when the knowledge summary is written.
