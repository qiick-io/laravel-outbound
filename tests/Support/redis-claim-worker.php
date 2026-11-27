<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\WebhookIdClaim;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

/** Open a fresh connection to the loopback service owned by the test run. */
function outboundRedisClaimStore(int $port): CacheSeenWebhookIdStore
{
    $redis = new RedisManager(new Container, 'phpredis', [
        'default' => ['host' => '127.0.0.1', 'port' => $port, 'database' => 0],
    ]);

    return new CacheSeenWebhookIdStore(new Repository(new RedisStore($redis, 'outbound-test:')));
}

// The Pest process imports the connection factory. Each separate PHP
// process waits at stdin before attempting a claim with its own connection.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $store = outboundRedisClaimStore((int) $argv[1]);
    $claim = WebhookIdClaim::generate($argv[2], 'msg_fixture');
    fwrite(STDOUT, "ready\n");

    if (fgets(STDIN) !== "start\n") {
        exit(1);
    }

    $outcome = $store->claim($claim, 60) ? 'won' : 'lost';
    fwrite(STDOUT, "{$outcome} {$claim->token}\n");
}
