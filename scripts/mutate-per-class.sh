#!/usr/bin/env bash
# Run pest mutation per security class so each stays >= MUTATE_MIN (default 95).
# Usage: scripts/mutate-per-class.sh [extra pest args...]
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

min="${MUTATE_MIN:-95}"
paths="${MUTATE_PATHS:-tests/Unit tests/Feature/RealTransport}"

classes=(
  'Qiick\Outbound\Ssrf\RemoteUrlGuard'
  'Qiick\Outbound\Ssrf\AuthorizedDestination'
  'Qiick\Outbound\Http\PinnedHttpClient'
  'Qiick\Outbound\Webhooks\WebhookSecret'
  'Qiick\Outbound\Webhooks\StandardWebhookSigner'
  'Qiick\Outbound\Webhooks\StandardWebhookVerifier'
)

status=0

for class in "${classes[@]}"; do
  echo "==> mutate ${class} (min=${min})"
  if ! vendor/bin/pest --mutate --class="${class}" --covered-only --min="${min}" ${paths} "$@"; then
    status=1
  fi
done

exit "${status}"
