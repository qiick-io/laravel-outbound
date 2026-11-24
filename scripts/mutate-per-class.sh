#!/usr/bin/env bash
# Run pest mutation per class so each stays >= MUTATE_MIN (default 95).
# Usage: scripts/mutate-per-class.sh [extra pest args...]
# MUTATE_SHARD=i/n runs only every n-th class, starting with the i-th, so CI
# can split the run over parallel jobs; unset runs every class.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

min="${MUTATE_MIN:-95}"
# RealTransport is only needed to kill PinnedHttpClient mutants; running it for
# every class races php -S ports under parallel mutate workers.
unit_paths="${MUTATE_UNIT_PATHS:-tests/Unit}"
pin_paths="${MUTATE_PIN_PATHS:-tests/Unit tests/Feature/RealTransport}"
webhook_paths="${MUTATE_WEBHOOK_PATHS:-tests/Unit/Retry tests/Feature/Webhooks}"

# Classes from before the 1.0 completeness work still run with --covered-only.
covered_only_classes=(
  'Qiick\Outbound\Ssrf\RemoteUrlGuard'
  'Qiick\Outbound\Ssrf\AuthorizedDestination'
  'Qiick\Outbound\Http\PinnedHttpClient'
  'Qiick\Outbound\Webhooks\WebhookSecret'
  'Qiick\Outbound\Webhooks\StandardWebhookSigner'
  'Qiick\Outbound\Webhooks\StandardWebhookVerifier'
  'Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore'
  'Qiick\Outbound\Retry\RetrySchedule'
)

# Every class added since: no --covered-only, so every mutated line must be killed.
webhook_classes=(
  'Qiick\Outbound\Models\WebhookEndpoint'
  'Qiick\Outbound\Models\WebhookEndpointSecret'
  'Qiick\Outbound\Models\WebhookSubscription'
  'Qiick\Outbound\Models\WebhookDelivery'
  'Qiick\Outbound\Models\WebhookDeliveryAttempt'
  'Qiick\Outbound\Models\Concerns\PackageModelTrait'
  'Qiick\Outbound\Webhooks\WebhookSigningSet'
  'Qiick\Outbound\Webhooks\DatabaseWebhookSecretResolver'
  'Qiick\Outbound\Webhooks\SecretRotator'
  'Qiick\Outbound\Delivery\WebhookDeliveryStorageException'
  'Qiick\Outbound\Retry\RetryAfter'
  'Qiick\Outbound\Retry\RetryPolicy'
  'Qiick\Outbound\Retry\RetryDecision'
  'Qiick\Outbound\Delivery\DatabaseDeliveryStore'
  'Qiick\Outbound\Delivery\WebhookDeliveryCoordinator'
  'Qiick\Outbound\Console\Commands\WorkWebhookDeliveriesCommand'
  'Qiick\Outbound\Delivery\EndpointDisablePolicy'
  'Qiick\Outbound\Listeners\NotifyWebhookEndpointOwnerListener'
  'Qiick\Outbound\Notifications\WebhookEndpointDisabledNotification'
)

status=0

shard="${MUTATE_SHARD:-1/1}"
shard_index="${shard%/*}"
shard_count="${shard#*/}"
class_position=0
classes_run=0

# A malformed shard would run no class and still exit 0.
if ! [[ "${shard_index}" =~ ^[0-9]+$ && "${shard_count}" =~ ^[0-9]+$ ]] || ((shard_index < 1 || shard_index > shard_count)); then
  echo "MUTATE_SHARD must be i/n with 1 <= i <= n, not '${shard}'." >&2
  exit 1
fi

# Whether the next class in the overall order belongs to this shard.
in_this_shard() {
  class_position=$((class_position + 1))
  [[ $(((class_position - 1) % shard_count + 1)) -eq "${shard_index}" ]] || return 1
  classes_run=$((classes_run + 1))
}

for class in "${covered_only_classes[@]}"; do
  in_this_shard || continue
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

# Classes added since whose tests are unit tests: no --covered-only either.
unit_classes=(
  'Qiick\Outbound\Http\OutboundHttpException'
)

for class in "${unit_classes[@]}"; do
  in_this_shard || continue
  echo "==> mutate ${class} (min=${min})"
  # shellcheck disable=SC2086
  if ! vendor/bin/pest --mutate --class="${class}" --min="${min}" ${unit_paths} "$@"; then
    status=1
  fi
done

for class in "${webhook_classes[@]}"; do
  in_this_shard || continue
  echo "==> mutate ${class} (min=${min})"
  # shellcheck disable=SC2086
  if ! vendor/bin/pest --mutate --class="${class}" --min="${min}" ${webhook_paths} "$@"; then
    status=1
  fi
done

echo "==> shard ${shard} ran ${classes_run} class(es)"

if ((classes_run == 0)); then
  echo "Shard ${shard} ran no class." >&2
  exit 1
fi

exit "${status}"
