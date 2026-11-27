<?php

declare(strict_types=1);

namespace Qiick\Outbound\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;

/**
 * Clears the bodies of deliveries whose retention has ended, keeping their
 * hash and length (standards review R5, M12), then deletes settled delivery
 * rows past `delivery_retention_days` with their attempts.
 *
 * The package schedules it daily on one server (fleet convention F9)
 * unless `outbound.webhooks.schedule` is off. Both steps delete in blocks
 * of 500 (DatabaseDeliveryStore).
 *
 * @internal
 */
final class PruneWebhookPayloadsCommand extends Command
{
    /** @internal Temporary beta alias. */
    public const LEGACY_NAME = 'outbound:webhooks:prune';

    /** @var string */
    protected $signature = 'outbound:prune-deliveries
        {--dry-run : Report what would be cleared and deleted, and change nothing}';

    /** @var string */
    protected $description = 'Clear expired webhook bodies and delete expired deliveries';

    public function handle(DatabaseDeliveryStore $store): int
    {
        if ($this->getName() === self::LEGACY_NAME) {
            Log::warning('outbound:webhooks:prune is deprecated. Use outbound:prune-deliveries.');
        }

        // One instant for both passes, so the dry run reports what the run does.
        $now = CarbonImmutable::now('UTC');

        if ($this->option('dry-run') === true) {
            ['payloads' => $expiredBodies, 'deliveries' => $expiredDeliveries] = $store->countExpired($now);
            $this->info("Would prune {$expiredBodies} webhook delivery bodies and delete {$expiredDeliveries} webhook deliveries (dry run, nothing changed).");

            return self::SUCCESS;
        }

        $prunedBodies = $store->pruneExpiredPayloads($now);
        $deletedDeliveries = $store->deleteExpiredDeliveries($now);

        $this->info("Pruned {$prunedBodies} webhook delivery bodies and deleted {$deletedDeliveries} webhook deliveries.");

        return self::SUCCESS;
    }
}
