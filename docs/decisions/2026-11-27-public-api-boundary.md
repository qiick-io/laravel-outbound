# 2026-11-27: the public API boundary for 1.0

Decided by the agent preparing the 1.0 release candidate, applying decision D6 of 2026-11-24 (`2026-11-24-1-0-gap-decisions.md`), for review by the maintainer in the RC review.

## What

- `EndpointDisablePolicy`, `NotifyWebhookEndpointOwnerListener`, `PackageModelTrait` and every console command class are `@internal`, as D6 says. The command **names** (`outbound:work-deliveries`, `outbound:prune-deliveries`, `outbound:check`) are public.
- `AuthorizedDestination` stays `@api`, against the letter of D6: `RemoteUrlGuard::authorize()` and `tryAuthorize()` return it to every caller (`laravel-oidc` reads its `pinnedAddress` in its guard tests). A public method cannot return an internal type.
- `DnsHostResolver` is `@api`: it is the default `HostResolver` a product decorates.
- `docs/public-api.md` lists every `@api` class and the frozen contracts other packages build on; `tests/Arch/PublicApiTest.php` keeps both in step.

## Why

The 1.0 freeze needs one list a reviewer and a product can check. D6 named the internal classes before the guard's return type was considered; keeping `AuthorizedDestination` public is the only reading under which the URL guard contract OIDC and MCP OAuth wait on is complete.
