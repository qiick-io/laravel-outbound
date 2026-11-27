#!/usr/bin/env bash
# Enforce at least 95% mutation coverage per source file. Everything in src/
# participates in a full run, including the fakes shipped to products.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

min="${MUTATE_MIN:-95}"
scope="${MUTATE_SCOPE:-all}"
shard="${MUTATE_SHARD:-1/1}"
shard_index="${shard%/*}"
shard_count="${shard#*/}"

if ! [[ "$shard_index" =~ ^[1-9][0-9]*$ && "$shard_count" =~ ^[1-9][0-9]*$ ]] || ((shard_index > shard_count)); then
  echo "MUTATE_SHARD must be i/n with 1 <= i <= n, not '${shard}'." >&2
  exit 1
fi

case "$scope" in
  all) paths="$(find src -type f -name '*.php' -print | LC_ALL=C sort)" ;;
  changed)
    paths="$(node -e '
      const paths = JSON.parse(process.env.MUTATE_CLASSES ?? "[]");
      if (!Array.isArray(paths) || paths.some((path) => typeof path !== "string" || !/^src\/(?:\w+\/)*\w+\.php$/.test(path))) {
        throw new Error("MUTATE_CLASSES must list PHP source paths under src/");
      }
      for (const path of [...new Set(paths)].sort()) console.log(path);
    ')"
    ;;
  *) echo "MUTATE_SCOPE must be all or changed, not '${scope}'." >&2; exit 1 ;;
esac

if [[ "$scope" == all && -z "$paths" ]]; then
  echo 'No PHP source files found for full mutation.' >&2
  exit 1
fi

status=0
position=0
files_run=0

while IFS= read -r path; do
  # A class removed by the PR has no code left to mutate.
  [[ -f "$path" ]] || continue
  position=$((position + 1))
  (( (position - 1) % shard_count + 1 == shard_index )) || continue

  # Keep expensive fixtures out of unrelated mutation subprocesses. New
  # namespaces use the package suite until they have a narrower test scope.
  case "$path" in
    src/Http/PinnedHttpClient.php)
      test_paths=(tests/Unit tests/Feature/RealTransport) ;;
    src/Console/Commands/CheckOutboundCommand.php)
      test_paths=(tests/Feature/CheckCommandTest.php) ;;
    src/Webhooks/CacheSeenWebhookIdStore.php)
      test_paths=(tests/Unit tests/Feature/Webhooks/DatabaseClaimTest.php) ;;
    src/Webhooks/DatabaseWebhookIdLock.php)
      test_paths=(tests/Feature/Webhooks/DatabaseClaimTest.php) ;;
    src/Erasure/*|src/Events/SubjectErasedEvent.php)
      test_paths=(tests/Feature/Erasure tests/Erasure) ;;
    src/Delivery/DeliveryAttempt.php|src/Http/*|src/Retry/RetrySchedule.php|src/Retry/SystemRandomizer.php|src/Ssrf/*|src/Testing/*|src/Webhooks/StandardWebhook*.php|src/Webhooks/WebhookIdClaim.php|src/Webhooks/WebhookMessage.php|src/Webhooks/WebhookSecret.php)
      test_paths=(tests/Unit) ;;
    src/Console/*|src/Delivery/*|src/Events/*|src/Listeners/*|src/Models/*|src/Notifications/*|src/Retry/*|src/Webhooks/*)
      test_paths=(tests/Unit/Retry tests/Feature/Webhooks) ;;
    *)
      test_paths=(tests/Arch tests/Unit tests/Feature tests/Erasure) ;;
  esac

  echo "==> mutate ${path} (min=${min})"
  # Interfaces, enums and payload-only classes still go through Pest, which
  # records "No mutations created" for files with no generated mutants.
  if ! vendor/bin/pest --mutate --path="$path" --min="$min" --ignore-min-score-on-zero-mutations \
      --fail-on-empty-test-suite "${test_paths[@]}" "$@"; then
    status=1
  fi
  files_run=$((files_run + 1))
done < <(printf '%s\n' "$paths")

echo "==> shard ${shard} ran ${files_run} source file(s)"
if [[ "$scope" == all ]] && ((files_run == 0)); then
  echo "Full mutation shard ${shard} ran no source files." >&2
  exit 1
fi

exit "$status"
