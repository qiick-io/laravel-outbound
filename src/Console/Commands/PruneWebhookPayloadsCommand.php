<?php

declare(strict_types=1);

namespace Qiick\Outbound\Console\Commands;

use Illuminate\Console\Command;
use Qiick\Outbound\Delivery\DatabaseDeliveryStore;

/**
 * Clears the bodies of deliveries whose retention has ended, keeping their
 * hash and length (standards review R5, M12).
 *
 * Run it from the scheduler, for example
 * `Schedule::command('outbound:webhooks:prune')->daily()`.
 *
 * @api
 */
final class PruneWebhookPayloadsCommand extends Command
{
    /** @var string */
    protected $signature = 'outbound:webhooks:prune';

    /** @var string */
    protected $description = 'Clear webhook delivery bodies past their retention period';

    public function handle(DatabaseDeliveryStore $store): int
    {
        $this->info("Pruned {$store->pruneExpiredPayloads()} webhook delivery bodies.");

        return self::SUCCESS;
    }
}
