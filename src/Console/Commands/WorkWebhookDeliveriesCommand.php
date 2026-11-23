<?php

declare(strict_types=1);

namespace Qiick\Outbound\Console\Commands;

use Illuminate\Console\Command;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;

/**
 * Sends one attempt for each due webhook delivery, up to `--limit`.
 *
 * Run it from the scheduler, for example
 * `Schedule::command('outbound:webhooks:work')->everyFiveSeconds()`:
 * retries are driven by `next_attempt_at`, so the first retry (5 s) is only
 * as punctual as this schedule. Overlapping runs are safe: each delivery is
 * leased on its own, right before its send.
 *
 * @api
 */
final class WorkWebhookDeliveriesCommand extends Command
{
    /** @var string */
    protected $signature = 'outbound:webhooks:work {--limit=50 : Most deliveries to attempt in this run}';

    /** @var string */
    protected $description = 'Send the due webhook delivery attempts';

    public function handle(WebhookDeliveryCoordinator $coordinator): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($limit === false) {
            $this->error('The --limit option must be a positive integer.');

            return self::FAILURE;
        }

        $this->info("Sent {$coordinator->work($limit)} webhook delivery attempt(s).");

        return self::SUCCESS;
    }
}
