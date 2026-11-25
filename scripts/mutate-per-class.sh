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

# No class runs with --covered-only: every mutated line must be killed.
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
  'Qiick\Outbound\Console\Commands\PruneWebhookPayloadsCommand'
  'Qiick\Outbound\Delivery\EndpointDisablePolicy'
  'Qiick\Outbound\Listeners\NotifyWebhookEndpointOwnerListener'
  'Qiick\Outbound\Webhooks\EndpointCreatorRecipients'
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

echo_and_mutate() {
  local class="$1"
  shift
  echo "==> mutate ${class} (min=${min})"
  # shellcheck disable=SC2086
  if ! vendor/bin/pest --mutate --class="${class}" --min="${min}" "$@"; then
    status=1
  fi
}

# The provider's bindings are exercised by the whole suite.
if in_this_shard; then
  echo_and_mutate 'Qiick\Outbound\Laravel\OutboundServiceProvider' tests "$@"
fi

# PinnedHttpClient's transport mutants are killed only on the real wire.
if in_this_shard; then
  # shellcheck disable=SC2086
  echo_and_mutate 'Qiick\Outbound\Http\PinnedHttpClient' ${pin_paths} "$@"
fi

# Classes whose tests are unit tests.
unit_classes=(
  'Qiick\Outbound\Ssrf\RemoteUrlGuard'
  'Qiick\Outbound\Ssrf\AuthorizedDestination'
  'Qiick\Outbound\Webhooks\WebhookSecret'
  'Qiick\Outbound\Webhooks\StandardWebhookSigner'
  'Qiick\Outbound\Webhooks\StandardWebhookVerifier'
  'Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore'
  'Qiick\Outbound\Retry\RetrySchedule'
  'Qiick\Outbound\Retry\SystemRandomizer'
  'Qiick\Outbound\Ssrf\DnsHostResolver'
  'Qiick\Outbound\Ssrf\UrlGuardOptions'
  'Qiick\Outbound\Http\HttpRequestOptions'
  'Qiick\Outbound\Http\OutboundHttpLimits'
  'Qiick\Outbound\Http\OutboundRequest'
  'Qiick\Outbound\Delivery\DeliveryAttempt'
  'Qiick\Outbound\Testing\FakeHostResolver'
  'Qiick\Outbound\Testing\FakeOutboundHttpFactory'
  'Qiick\Outbound\Testing\FakeSeenWebhookIdStore'
  'Qiick\Outbound\Http\OutboundHttpException'
  'Qiick\Outbound\Http\EgressProxy'
  'Qiick\Outbound\Webhooks\WebhookMessage'
  'Qiick\Outbound\Webhooks\WebhookIdClaim'
)

for class in "${unit_classes[@]}"; do
  in_this_shard || continue
  # shellcheck disable=SC2086
  echo_and_mutate "${class}" ${unit_paths} "$@"
done

for class in "${webhook_classes[@]}"; do
  in_this_shard || continue
  # shellcheck disable=SC2086
  echo_and_mutate "${class}" ${webhook_paths} "$@"
done

echo "==> shard ${shard} ran ${classes_run} class(es)"

if ((classes_run == 0)); then
  echo "Shard ${shard} ran no class." >&2
  exit 1
fi

exit "${status}"
