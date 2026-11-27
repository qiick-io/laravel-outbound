# 2026-11-27: the public API boundary for 1.0

Applies decision D6 of 2026-11-24 (`2026-11-24-1-0-gap-decisions.md`). The
`AuthorizedDestination` exception below was approved by Team Leader on behalf
of the maintainer (Luca), 2026-11-27. [PR #98](https://github.com/qiick-io/laravel-outbound/pull/98)

## What

- `EndpointDisablePolicy`, `NotifyWebhookEndpointOwnerListener`, `PackageModelTrait` and every console command class are `@internal`, as D6 says. The command **names** (`outbound:work-deliveries`, `outbound:prune-deliveries`, `outbound:check`) are public.
- `AuthorizedDestination` stays `@api`, against the letter of D6: `RemoteUrlGuard::authorize()` and `tryAuthorize()` return it to every caller (`laravel-oidc` reads its `pinnedAddress` in its guard tests). A public method cannot return an internal type.
- `DnsHostResolver` is `@api`: it is the default `HostResolver` a product decorates.
- `docs/public-api.md` lists every `@api` class and the frozen contracts other packages build on; `tests/Arch/PublicApiTest.php` keeps both in step.

Team Leader also approved resolving the transport, signer, verifier, rotator,
retry policy and guard through the container. Their constructors are internal,
the verifier always claims IDs, and the transport's test fixtures live behind
an internal factory. OIDC and other consumers with an independent destination
policy build a guard and pinned client through `OutboundHttpStackData::make()`.
The beta attempt schema drops the unused response excerpt
column so the signed webhook API carries only the documented diagnostics.

## Why

The 1.0 freeze needs one list a reviewer and a product can check. D6 named the internal classes before the guard's return type was considered; keeping `AuthorizedDestination` public is the only reading under which the URL guard contract OIDC and MCP OAuth wait on is complete.
