<?php

declare(strict_types=1);

namespace Qiick\Outbound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;

/**
 * Sends one attempt for each due webhook delivery, up to `--limit`.
 *
 * Run it from the scheduler, for example
 * `Schedule::command('outbound:work-deliveries')->everyFiveSeconds()`:
 * retries are driven by `next_attempt_at`, so the first retry (5 s) is only
 * as punctual as this schedule. Overlapping runs are safe: each delivery is
 * leased on its own, right before its send.
 *
 * @internal
 */
final class WorkWebhookDeliveriesCommand extends Command
{
    public const LEGACY_NAME = 'outbound:webhooks:work';

    /** @var string */
    protected $signature = 'outbound:work-deliveries
        {--limit=50 : Most deliveries to attempt in this run}
        {--max-seconds= : Start no attempt after this many seconds (none by default)}';

    /** @var string */
    protected $description = 'Send the due webhook delivery attempts';

    public function handle(WebhookDeliveryCoordinator $coordinator): int
    {
        if ($this->getName() === self::LEGACY_NAME) {
            Log::warning('outbound:webhooks:work is deprecated. Use outbound:work-deliveries.');
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($limit === false) {
            $this->error('The --limit option must be a positive integer.');

            return self::FAILURE;
        }

        $maxSeconds = $this->option('max-seconds') === null
            ? null
            : filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($maxSeconds === false) {
            $this->error('The --max-seconds option must be a positive integer.');

            return self::FAILURE;
        }

        $this->info("Sent {$coordinator->work($limit, $maxSeconds)} webhook delivery attempt(s).");

        return self::SUCCESS;
    }
}
