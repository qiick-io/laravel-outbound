<?php

declare(strict_types=1);

namespace Qiick\Outbound\Console\Commands;

use Illuminate\Console\Command;
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

/**
 * The deploy check (fleet convention F2): what the boot does not verify.
 * A config value of the wrong type already stops the boot (F1), so this
 * command running at all proves the config; it adds the webhook id dedupe
 * store and the tables. One line per problem, exit 1 on any.
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

    public function handle(): int
    {
        $problems = [...$this->dedupeStoreProblems(), ...$this->tableProblems()];

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->info('outbound: configuration and environment are ready.');

        return self::SUCCESS;
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
            new CacheSeenWebhookIdStore($this->laravel->make(CacheRepository::class), allowArrayStore: $this->laravel->environment('testing'));
        } catch (UnsupportedWebhookIdCacheStoreException $exception) {
            return ["cache.default: {$exception->getMessage()}"];
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

        if (is_string($usersTable) && ! Schema::hasTable($usersTable)) {
            $problems[] = "outbound.webhooks.users_table: table {$usersTable} is missing.";
        }

        return $problems;
    }
}
