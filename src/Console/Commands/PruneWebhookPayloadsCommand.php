<?php

declare(strict_types=1);

namespace Qiick\Outbound\Console\Commands;

use Illuminate\Console\Command;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;

/**
 * Clears the bodies of deliveries whose retention has ended, keeping their
 * hash and length (standards review R5, M12), then deletes settled delivery
 * rows past `delivery_retention_days` with their attempts.
 *
 * Run it from the scheduler, for example
 * `Schedule::command('outbound:prune-deliveries')->daily()`.
 *
 * @api
 */
final class PruneWebhookPayloadsCommand extends Command
{
    /** @var string */
    protected $signature = 'outbound:prune-deliveries';

    /** @var string */
    protected $description = 'Clear webhook delivery bodies past their retention period';

    public function handle(DatabaseDeliveryStore $store): int
    {
        $prunedBodies = $store->pruneExpiredPayloads();
        $deletedDeliveries = $store->deleteExpiredDeliveries();

        $this->info("Pruned {$prunedBodies} webhook delivery bodies and deleted {$deletedDeliveries} webhook deliveries.");

        return self::SUCCESS;
    }
}
