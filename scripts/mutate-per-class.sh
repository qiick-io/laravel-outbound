#!/usr/bin/env bash
# Run pest mutation per security class so each stays >= MUTATE_MIN (default 95).
# Usage: scripts/mutate-per-class.sh [extra pest args...]
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

min="${MUTATE_MIN:-95}"
# RealTransport is only needed to kill PinnedHttpClient mutants; running it for
# every class races php -S ports under parallel mutate workers.
unit_paths="${MUTATE_UNIT_PATHS:-tests/Unit}"
pin_paths="${MUTATE_PIN_PATHS:-tests/Unit tests/Feature/RealTransport}"

classes=(
  'Qiick\Outbound\Ssrf\RemoteUrlGuard'
  'Qiick\Outbound\Ssrf\AuthorizedDestination'
  'Qiick\Outbound\Http\PinnedHttpClient'
  'Qiick\Outbound\Webhooks\WebhookSecret'
  'Qiick\Outbound\Webhooks\StandardWebhookSigner'
  'Qiick\Outbound\Webhooks\StandardWebhookVerifier'
  'Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore'
  'Qiick\Outbound\Retry\RetrySchedule'
)

status=0

for class in "${classes[@]}"; do
  paths="$unit_paths"
  if [[ "$class" == 'Qiick\Outbound\Http\PinnedHttpClient' ]]; then
    paths="$pin_paths"
  fi

  echo "==> mutate ${class} (min=${min})"
  # shellcheck disable=SC2086
  if ! vendor/bin/pest --mutate --class="${class}" --covered-only --min="${min}" ${paths} "$@"; then
    status=1
  fi
done

exit "${status}"
