<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/header-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/header-light.svg">
    <img alt="laravel-outbound: Safe outbound HTTP and webhooks for Laravel" src=".github/assets/header-light.svg" width="100%">
  </picture>
</p>

# qiick/laravel-outbound

> **In development. Not released and not on Packagist yet: do not depend on it.**

SSRF-safe outbound HTTP and Standard Webhooks signing for qiick Laravel products.

## Status

This package is under active construction against the approved design
(`projects/qiick/packages/laravel-outbound-design.md` in the qiick knowledge
base). Public APIs, evaluation order and security properties are binding once
shipped; until `0.1.0` lands on Packagist, treat every surface as unstable.

## Purpose

One canonical path for:

- **SSRF-safe outbound HTTP** — curl-style IPv4 reading, metadata blocking
  (including Alibaba/Oracle even when private hosts are allowed), resolve-all
  DNS checks, and `CURLOPT_CONNECT_TO` pinning (never `CURLOPT_RESOLVE` alone).
- **Standard Webhooks** — symmetric `v1` signing and verification over the
  exact raw body bytes, multi-secret rotation bounds, and pure retry schedules.

Non-goals for v1: delivery tables/migrations, queue job bases / auto-disable
enforcement (product duty), asymmetric Standard Webhooks (`v2a`), global
helpers, a hard dependency on `laravel/framework`.

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
- Signature forgery or replay on inbound Standard Webhooks (exact raw body,
  integer timestamps, tolerance window, `hash_equals`)

Products that adopt this package must still enforce per-endpoint concurrency
caps, timeouts, circuit-break / auto-disable after repeated failures, and
honour `Retry-After` / 410 / 429. The package documents that duty; it does not
ship auto-disable.

## Requirements

- PHP ^8.3 with `ext-curl` and `ext-intl`
- illuminate/contracts, illuminate/http, illuminate/support ^12 or ^13
- guzzlehttp/guzzle ^7.8 or ^8.0

## Install (when released)

```bash
composer require qiick/laravel-outbound
php artisan vendor:publish --tag=outbound-config
```

The service provider is auto-discovered.


## Public API (so far)

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

$verifier = app(StandardWebhookVerifier::class);
$verifier->verify(
    $rawBody,
    $signed->headers['webhook-id'],
    $signed->headers['webhook-timestamp'],
    $signed->headers['webhook-signature'],
    [$secret],
    new DateTimeImmutable('now'),
);
```

Signing uses exact raw body bytes (`msg_id.timestamp.body`). Multi-secret
rotation emits space-separated `v1,<base64>` signatures. Verification fails
closed on non-integer timestamps, replay outside tolerance (both directions),
oversize payloads, too many signatures/secrets, and uses `hash_equals`.
`WebhookSecret::parseAllowingLegacyRaw()` accepts non-`whsec_` base64 only when
`outbound.webhooks.allow_raw_legacy_secret` is true. Secrets use
`#[\SensitiveParameter]` and are redacted in `__debugInfo` / string casts.

Official vectors: `tests/Fixtures/StandardWebhooks/` (standard-webhooks
`@ 3d65b1824b6697f05a5a4584e7062bd23512fc8e`).

## Configuration

| Config key | Env | Default |
| --- | --- | --- |
| `outbound.ssrf.allow_private_hosts` | `OUTBOUND_ALLOW_PRIVATE_HOSTS` | `false` |
| `outbound.ssrf.https_only` | `OUTBOUND_HTTPS_ONLY` | `false` |
| `outbound.http.timeout_seconds` | `OUTBOUND_TIMEOUT_SECONDS` | `5` |
| `outbound.http.connect_timeout_seconds` | `OUTBOUND_CONNECT_TIMEOUT_SECONDS` | `3` |
| `outbound.http.max_timeout_seconds` | `OUTBOUND_MAX_TIMEOUT_SECONDS` | `60` |
| `outbound.http.max_connect_timeout_seconds` | `OUTBOUND_MAX_CONNECT_TIMEOUT_SECONDS` | `30` |
| `outbound.http.total_deadline_seconds` | `OUTBOUND_TOTAL_DEADLINE_SECONDS` | `30` |
| `outbound.http.max_response_bytes` | `OUTBOUND_MAX_RESPONSE_BYTES` | `1048576` |
| `outbound.http.max_redirects` | `OUTBOUND_MAX_REDIRECTS` | `0` |
| `outbound.webhooks.replay_tolerance_seconds` | `OUTBOUND_WEBHOOK_TOLERANCE_SECONDS` | `300` |
| `outbound.webhooks.max_payload_bytes` | `OUTBOUND_WEBHOOK_MAX_PAYLOAD_BYTES` | `1048576` |
| `outbound.webhooks.max_signatures` | `OUTBOUND_WEBHOOK_MAX_SIGNATURES` | `8` |
| `outbound.webhooks.max_secrets` | `OUTBOUND_WEBHOOK_MAX_SECRETS` | `4` |
| `outbound.webhooks.retry_preset` | `OUTBOUND_WEBHOOK_RETRY_PRESET` | `standard` |
| `outbound.webhooks.retry_jitter_seconds` | `OUTBOUND_WEBHOOK_RETRY_JITTER` | `30` |
| `outbound.webhooks.allow_raw_legacy_secret` | `OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET` | `false` |

## Development

```bash
composer update
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan level max
```

Mutation (CI): `bash scripts/mutate-per-class.sh` — each listed security class
must score ≥95% (`RemoteUrlGuard`, `AuthorizedDestination`, `PinnedHttpClient`, `WebhookSecret`, `StandardWebhookSigner`, `StandardWebhookVerifier`;
more classes as they land). No method-level `@pest-mutate-ignore`.

Tests are deterministic: no live network. DNS and HTTP use injectable fakes;
clock and randomizer are fixed in tests.

## Licence

MIT. See [LICENSE](LICENSE).

## Security

See [SECURITY.md](SECURITY.md). Use GitHub private vulnerability reporting; do
not open a public issue for security findings.
