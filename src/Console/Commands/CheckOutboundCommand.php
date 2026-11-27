<?php

declare(strict_types=1);

namespace Qiick\Outbound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;
use Qiick\Outbound\Models\WebhookSubscription;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\UnsupportedWebhookIdCacheStoreException;
use Qiick\Outbound\Webhooks\WebhookIdClaim;
use Qiick\Outbound\Webhooks\WebhookIdStoreFailedException;

/**
 * The deploy check (fleet convention F2): what the boot does not verify.
 * A config value of the wrong type already stops the boot (F1), so this
 * command running at all proves the config; it adds the webhook id dedupe
 * store (with a probe claim) and the tables. One line per problem, exit 1 on any.
 *
 * @internal
 */
final class CheckOutboundCommand extends Command
{
    /** @var string */
    protected $signature = 'outbound:check';

    /** @var string */
    protected $description = 'Check the outbound configuration and environment for a deploy';

    /** @var list<class-string<Model>> */
    private const MODEL_CLASSES = [
        WebhookEndpoint::class,
        WebhookEndpointSecret::class,
        WebhookSubscription::class,
        WebhookDelivery::class,
        WebhookDeliveryAttempt::class,
    ];

    public function handle(Schedule $schedule): int
    {
        // A failed database-backed cache query can abort the current
        // transaction on PostgreSQL. Inspect the schema before the probe.
        $problems = [...$this->tableProblems(), ...$this->scheduleProblems($schedule), ...$this->dedupeStoreProblems()];

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->info('outbound: configuration and environment are ready.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function scheduleProblems(Schedule $schedule): array
    {
        $replacements = [
            WorkWebhookDeliveriesCommand::LEGACY_NAME => 'outbound:work-deliveries',
            PruneWebhookPayloadsCommand::LEGACY_NAME => 'outbound:prune-deliveries',
        ];
        $problems = [];

        foreach ($schedule->events() as $event) {
            if (preg_match('/(?:^|\s)[\'"]?artisan[\'"]?\s+[\'"]?(outbound:webhooks:\w+)[\'"]?(?:\s|$)/', $event->command ?? '', $matches) === 1
                && isset($replacements[$matches[1]])) {
                $problems[] = "schedule: replace {$matches[1]} with {$replacements[$matches[1]]}.";
            }
        }

        return $problems;
    }

    /**
     * The package's dedupe store refuses a cache whose `add()` is not atomic
     * across processes (ArrayStore outside tests), which would let a
     * replayed webhook through on another worker. Built over the default
     * cache as the provider builds it.
     *
     * @return list<string>
     */
    private function dedupeStoreProblems(): array
    {
        try {
            $store = new CacheSeenWebhookIdStore($this->laravel->make(CacheRepository::class), allowArrayStore: $this->laravel->environment('testing'));
        } catch (UnsupportedWebhookIdCacheStoreException $exception) {
            return ["cache.default: {$exception->getMessage()}"];
        }

        // A probe claim, given back at once: a store that cannot be reached
        // (a database store without its table, Redis down) fails here, not
        // on the first webhook.
        $probeClaim = WebhookIdClaim::generate('outbound:check', bin2hex(random_bytes(8)));

        try {
            $store->claim($probeClaim, 60);
            $store->release($probeClaim);
        } catch (WebhookIdStoreFailedException $exception) {
            // The previous exception may carry a connection string: only its class is shown.
            $cause = $exception->getPrevious() === null ? $exception::class : $exception->getPrevious()::class;

            return ["cache.default: the webhook id dedupe store cannot claim an id ({$cause})."];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function tableProblems(): array
    {
        $problems = [];

        foreach (self::MODEL_CLASSES as $modelClass) {
            $model = new $modelClass;

            if (! Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
                $problems[] = "database: table {$model->getTable()} is missing (run the migrations).";
            }
        }

        $usersTable = $this->laravel->make('config')->get('outbound.webhooks.users_table');

        // Null or empty: the migrations create no users foreign keys.
        if (is_string($usersTable) && $usersTable !== '' && ! Schema::hasTable($usersTable)) {
            $problems[] = "outbound.webhooks.users_table: table {$usersTable} is missing.";
        }

        return $problems;
    }
}
