# Public API

The deprecation policy below governs the public API. Everything else in `src/` is marked `@internal` and may change in any release. `tests/Arch/PublicApiTest.php` verifies the class and configuration inventories, the delivery status cases and the signatures other packages call.

## Frozen contracts other packages build on

`qiick/laravel-oidc` and `qiick/laravel-mcp-oauth` require these at 1.0; they change only in a major release.

- **URL guard:** `RemoteUrlGuard::authorize()`, `tryAuthorize()` (`['ok' => true, 'destination' => AuthorizedDestination]` or `['ok' => false, 'reason' => string]`) and `assertBrowserUrlIsSafe()`, with `UrlGuardOptions`, `PortPolicy`, `UnsafeUrl` and the `HostResolver` / `MetadataPolicyContract` seams. What it refuses is the contract (README, "Threat model", and `docs/security-checklist.md`); the order of its checks and the wording of a `reason` are not. A policy can only add blocked hosts.
- **Claim store:** `SeenWebhookIdStore::claim(WebhookIdClaim $claim, int $ttlSeconds): bool` atomically reserves the scope and webhook id. `release(WebhookIdClaim $claim): void` atomically deletes only the record owned by the claim's token. Make a new claim with `WebhookIdClaim::generate($scope, $webhookId)`. On a failed store operation the package throws `WebhookIdStoreFailedException`.
- **Dedupe scope:** `StandardWebhookVerifier::verify(..., string $dedupeScope)` is mandatory. The caller owns its isolation: two tenants that pass the same scope share one webhook-id namespace, so a product passes a value unique per tenant and sender (for example the endpoint's public id).
- **Delivery status:** `DeliveryStatus::Pending = 'pending'`, `Delivered = 'delivered'`, `Failed = 'failed'`, `Refused = 'refused'`, `Exhausted = 'exhausted'`, `Cancelled = 'cancelled'`; the backing values are stored in `webhook_deliveries.status`.
- **Subject reference:** `WebhookDeliveryCoordinator::dispatch(WebhookMessage $message, ?string $scope = null, ?string $subjectPublicId = null)`; `subjectPublicId` is stored lower-case in `webhook_deliveries.subject_public_id` and is what `OutboundEraser` matches.

Resolve `PinnedHttpClient`, `RemoteUrlGuard`, `StandardWebhookSigner`,
`StandardWebhookVerifier`, `SecretRotator`, `RetryPolicy` and
`WebhookDeliveryCoordinator` from the Laravel container. Their constructors
are `@internal`. The verifier requires a `SeenWebhookIdStore` and returns
the winning `WebhookIdClaim` after successful verification.

## Classes, interfaces and enums

The public API covers class names, public members without an `@internal`
annotation and enum case names and backing values. The Eloquent models
expose their tables, columns, casts, relations and scopes.

### `Qiick\Outbound\Contracts`

- `HostResolver` (interface)
- `LegacyWebhookProfileContract` (interface)
- `MetadataPolicyContract` (interface)
- `WebhookEndpointNoticeRecipientsContract` (interface)

### `Qiick\Outbound\Delivery`

- `DeliveryAttempt` (class)
- `DeliveryStatus` (enum)
- `DuplicateWebhookDeliveryException` (class)
- `WebhookDeliveryConcurrencyException` (class)
- `WebhookDeliveryCoordinator` (class)
- `WebhookDeliveryStorageException` (class)

### `Qiick\Outbound\Enums`

- `OutboundTransportErrorKindEnum` (enum)
- `WebhookAttemptStatusEnum` (enum)
- `WebhookAttemptTriggerEnum` (enum)
- `WebhookDeliveryTriggerEnum` (enum)
- `WebhookEndpointDisabledReasonEnum` (enum)
- `WebhookExhaustedReasonEnum` (enum)
- `WebhookRetryPresetEnum` (enum)
- `WebhookSignatureSchemeEnum` (enum)

### `Qiick\Outbound\Erasure`

- `ErasureReport` (class)
- `OrganisationScopeUnsupportedException` (class)
- `OutboundEraser` (class)

### `Qiick\Outbound\Events`

- `OutboundRequestBlockedEvent` (class)
- `SubjectErasedEvent` (class)
- `WebhookDeliveredEvent` (class)
- `WebhookDeliveryFailedEvent` (class)
- `WebhookEndpointDisabledEvent` (class)
- `WebhookRedeliveryRequestedEvent` (class)
- `WebhookSignedEvent` (class)

### `Qiick\Outbound\Http`

- `EgressProxy` (class)
- `HttpRequestOptions` (class)
- `OutboundHttpException` (class)
- `OutboundHttpLimits` (class)
- `OutboundRequest` (class)
- `PinnedHttpClient` (class)

### `Qiick\Outbound\Laravel`

- `OutboundServiceProvider` (class)

### `Qiick\Outbound\Models`

- `WebhookDelivery` (class)
- `WebhookDeliveryAttempt` (class)
- `WebhookEndpoint` (class)
- `WebhookEndpointSecret` (class)
- `WebhookSubscription` (class)

### `Qiick\Outbound\Notifications`

- `WebhookEndpointDisabledNotification` (class)

### `Qiick\Outbound\Retry`

- `Randomizer` (interface)
- `RetryAfter` (class)
- `RetryDecision` (class)
- `RetryPolicy` (class)
- `RetrySchedule` (class)
- `SystemRandomizer` (class)

### `Qiick\Outbound\Ssrf`

- `AuthorizedDestination` (class)
- `DnsHostResolver` (class)
- `PortPolicy` (interface)
- `RemoteUrlGuard` (class)
- `UnsafeUrl` (class)
- `UrlGuardOptions` (class)

### `Qiick\Outbound\Testing`

- `FakeHostResolver` (class)
- `FakeOutboundHttpFactory` (class)
- `FakeSeenWebhookIdStore` (class)

### `Qiick\Outbound\Webhooks`

- `CacheSeenWebhookIdStore` (class)
- `DatabaseWebhookSecretResolver` (class)
- `DuplicateWebhookIdException` (class)
- `InvalidWebhookSignatureException` (class)
- `SecretRotator` (class)
- `SeenWebhookIdStore` (interface)
- `SignedWebhook` (class)
- `StandardWebhookSigner` (class)
- `StandardWebhookVerifier` (class)
- `UnsupportedWebhookIdCacheStoreException` (class)
- `WebhookException` (class)
- `WebhookIdClaim` (class)
- `WebhookIdStoreFailedException` (class)
- `WebhookMessage` (class)
- `WebhookSecret` (class)
- `WebhookSecretResolver` (interface)
- `WebhookSigningSet` (class)

## Config keys and environment variables

Every key of `config/outbound.php` (types and defaults in the README, "Configuration"). A value of the wrong type stops the boot, naming the key; a key missing from an older published copy takes the shipped default.

| Key | Environment variable |
| --- | --- |
| `outbound.migrations.load` | `OUTBOUND_MIGRATIONS_LOAD` |
| `outbound.ssrf.allow_private_hosts` | `OUTBOUND_ALLOW_PRIVATE_HOSTS` |
| `outbound.ssrf.https_only` | `OUTBOUND_HTTPS_ONLY` |
| `outbound.http.timeout_seconds` | `OUTBOUND_TIMEOUT_SECONDS` |
| `outbound.http.connect_timeout_seconds` | `OUTBOUND_CONNECT_TIMEOUT_SECONDS` |
| `outbound.http.max_timeout_seconds` | `OUTBOUND_MAX_TIMEOUT_SECONDS` |
| `outbound.http.max_connect_timeout_seconds` | `OUTBOUND_MAX_CONNECT_TIMEOUT_SECONDS` |
| `outbound.http.total_deadline_seconds` | `OUTBOUND_TOTAL_DEADLINE_SECONDS` |
| `outbound.http.max_response_bytes` | `OUTBOUND_MAX_RESPONSE_BYTES` |
| `outbound.http.max_redirects` | `OUTBOUND_MAX_REDIRECTS` |
| `outbound.http.egress_proxy` | `OUTBOUND_EGRESS_PROXY` |
| `outbound.webhooks.replay_tolerance_seconds` | `OUTBOUND_WEBHOOK_TOLERANCE_SECONDS` |
| `outbound.webhooks.max_payload_bytes` | `OUTBOUND_WEBHOOK_MAX_PAYLOAD_BYTES` |
| `outbound.webhooks.max_signatures` | `OUTBOUND_WEBHOOK_MAX_SIGNATURES` |
| `outbound.webhooks.max_secrets` | `OUTBOUND_WEBHOOK_MAX_SECRETS` |
| `outbound.webhooks.secret_rotation_grace_seconds` | `OUTBOUND_WEBHOOK_SECRET_ROTATION_GRACE_SECONDS` |
| `outbound.webhooks.retry_preset` | `OUTBOUND_WEBHOOK_RETRY_PRESET` |
| `outbound.webhooks.retry_jitter_seconds` | `OUTBOUND_WEBHOOK_RETRY_JITTER` |
| `outbound.webhooks.timeout_seconds` | `OUTBOUND_WEBHOOK_TIMEOUT_SECONDS` |
| `outbound.webhooks.respect_retry_after` | `OUTBOUND_WEBHOOK_RESPECT_RETRY_AFTER` |
| `outbound.webhooks.retry_after_cap_seconds` | `OUTBOUND_WEBHOOK_RETRY_AFTER_CAP_SECONDS` |
| `outbound.webhooks.default_max_concurrency` | `OUTBOUND_WEBHOOK_DEFAULT_MAX_CONCURRENCY` |
| `outbound.webhooks.payload_retention_days` | `OUTBOUND_WEBHOOK_PAYLOAD_RETENTION_DAYS` |
| `outbound.webhooks.delivery_retention_days` | `OUTBOUND_WEBHOOK_DELIVERY_RETENTION_DAYS` |
| `outbound.webhooks.schedule` | `OUTBOUND_WEBHOOK_SCHEDULE` |
| `outbound.webhooks.auto_disable.enabled` | `OUTBOUND_WEBHOOK_AUTO_DISABLE` |
| `outbound.webhooks.auto_disable.no_success_days` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_NO_SUCCESS_DAYS` |
| `outbound.webhooks.auto_disable.min_consecutive_failures` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_MIN_CONSECUTIVE_FAILURES` |
| `outbound.webhooks.auto_disable.notify_owner` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_NOTIFY_OWNER` |
| `outbound.webhooks.auto_disable.endpoint_url` | `OUTBOUND_WEBHOOK_AUTO_DISABLE_ENDPOINT_URL` |
| `outbound.webhooks.allow_raw_legacy_secret` | `OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET` |
| `outbound.webhooks.legacy_profiles` | — |
| `outbound.webhooks.scope_column` | `OUTBOUND_WEBHOOK_SCOPE_COLUMN` |
| `outbound.webhooks.users_table` | `OUTBOUND_WEBHOOK_USERS_TABLE` |

## Commands

- `outbound:work-deliveries {--limit=50} {--max-seconds=}`: sends the due attempts.
- `outbound:prune-deliveries {--dry-run}`: clears expired bodies and deletes settled rows past their retention; scheduled by the package daily on one server unless `outbound.webhooks.schedule` is off.
- `outbound:check`: the deploy check (exit `1` on any problem, including an old command name still scheduled). The hidden beta aliases are removed in `1.0.0-rc.1`. [Alias removal #100](https://github.com/qiick-io/laravel-outbound/issues/100)

## Events

All in `Qiick\Outbound\Events`, dispatched synchronously (listeners must not throw; README, "Webhook delivery"): `OutboundRequestBlockedEvent`, `WebhookSignedEvent`, `WebhookDeliveredEvent` (after commit), `WebhookDeliveryFailedEvent`, `WebhookEndpointDisabledEvent`, `WebhookRedeliveryRequestedEvent`, `SubjectErasedEvent`. Their public properties are the payload. They carry public ids, never a secret or a body; `WebhookRedeliveryRequestedEvent::$triggeredBy` is the asking user's local id (bigint), for the product's own audit.

## Tables

`webhook_endpoints`, `webhook_endpoint_secrets`, `webhook_subscriptions`, `webhook_deliveries`, `webhook_delivery_attempts`, with the columns of their `create_` migrations in `database/migrations/`. `created_by`, `triggered_by`, `next_attempt_triggered_by` and `claimed_triggered_by` reference `outbound.webhooks.users_table` (bigint `id`); with the key null or empty they are plain nullable columns without a foreign key, the option for a product whose user ids are not bigints. The publish tags `outbound-config` and `outbound-migrations` are public too.

The 1.0 schema has one create migration per table. It includes the delivery
subject reference, nullable payload hash and active-claim initiator in the
delivery table's create migration. The measured claim plan used the existing
indexes, so it requires no additional index.
[#50 query plan](https://github.com/qiick-io/laravel-outbound/issues/50#issuecomment-7217419740)

## Routes and error codes

None: the package registers no route and answers no HTTP request, so it has no `error` codes of the fleet registry. Its exceptions are public classes above; their messages are not a contract.

## Deprecation policy

This package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html):

- **Deprecation in minor releases:** a deprecated public API (class, method, interface, config key, environment variable, command, event) is marked `@deprecated`, listed in `CHANGELOG.md` under `Deprecated` and in `UPGRADING.md` with its replacement, and keeps working for at least one minor release.
- **Removal in major releases only:** nothing public is removed or changed incompatibly within a major version; removals wait for the next major.
- **Before 1.0.0:** a minor may break; every break is in `UPGRADING.md` before the tag. From `1.0.0` on, the API and the migrations are frozen and follow SemVer.
