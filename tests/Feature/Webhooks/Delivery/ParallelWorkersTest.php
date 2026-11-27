<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Tests\Support\RecordingTransferHandler;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookMessage;

/*
 * Two `outbound:work-deliveries` runs at the same moment, in two processes with
 * two database connections, on a server database (the CI `databases` job):
 * every delivery is sent exactly once and no endpoint goes over its
 * concurrency (review of #25, B2). SQLite in memory cannot be shared between
 * processes, so the test needs PostgreSQL, MySQL or MariaDB.
 */

it('sends every delivery exactly once when two workers run in parallel', function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    $sendLog = tempnam(sys_get_temp_dir(), 'outbound-sends-');
    $endpointNames = ['a', 'b', 'c', 'd', 'e', 'f'];
    $resolverAnswers = [];

    foreach ($endpointNames as $endpointName) {
        $resolverAnswers["{$endpointName}.example.com"] = ['93.184.216.34'];
    }

    $transfer = new RecordingTransferHandler;
    app()->instance(PinnedHttpClient::class, PinnedHttpClient::forTesting(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: $resolverAnswers)),
        app(OutboundHttpLimits::class),
        null,
        function ($request, array $options) use ($transfer, $sendLog) {
            $startedAt = microtime(true);
            // Long enough for the two workers to overlap.
            usleep(150_000);
            file_put_contents($sendLog, sprintf("%s %.6F %.6F\n", $request->getUri()->getHost(), $startedAt, microtime(true)), FILE_APPEND | LOCK_EX);

            return $transfer($request, $options);
        },
    ));

    foreach ($endpointNames as $endpointName) {
        $endpoint = (new WebhookEndpoint)->forceFill(['url' => "https://{$endpointName}.example.com/receive", 'max_concurrency' => 1]);
        $endpoint->save();
        $endpoint->subscriptions()->create(['event_type' => 'invoice.paid']);
        app(SecretRotator::class)->issue($endpoint);
    }

    foreach (['msg_1', 'msg_2'] as $messageId) {
        app(WebhookDeliveryCoordinator::class)->dispatch(new WebhookMessage($messageId, 'invoice.paid', new DateTimeImmutable('2026-11-23 09:59:00'), []));
    }

    // The workers are other processes: they only see committed rows.
    $connection = DB::connection();
    while ($connection->transactionLevel() > 0) {
        $connection->commit();
    }

    try {
        $workerIds = [];

        foreach ([1, 2] as $ignored) {
            $workerId = pcntl_fork();

            if ($workerId === 0) {
                runForkedWorker($connection->getName());
            }

            $workerIds[] = $workerId;
        }

        foreach ($workerIds as $workerId) {
            pcntl_waitpid($workerId, $workerStatus);
        }

        $sendsByHost = [];

        foreach (array_filter(explode("\n", (string) file_get_contents($sendLog))) as $sendLine) {
            [$host, $startedAt, $endedAt] = explode(' ', $sendLine);
            $sendsByHost[$host][] = [(float) $startedAt, (float) $endedAt];
        }

        $overlappingHosts = array_keys(array_filter($sendsByHost, static function (array $sends): bool {
            sort($sends);

            // max_concurrency 1: the second send to a host starts after the first ended.
            return count($sends) === 2 && $sends[1][0] < $sends[0][1];
        }));

        expect(array_sum(array_map('count', $sendsByHost)))->toBe(12)
            ->and(array_unique(array_map('count', array_values($sendsByHost))))->toBe([2])
            ->and($overlappingHosts)->toBe([])
            ->and(WebhookDeliveryAttempt::query()->count())->toBe(12)
            ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(12);
    } finally {
        @unlink($sendLog);
        WebhookDeliveryAttempt::query()->delete();
        WebhookDelivery::query()->delete();
        DB::table('webhook_endpoint_secrets')->delete();
        DB::table('webhook_subscriptions')->delete();
        DB::table('webhook_endpoints')->delete();
        CarbonImmutable::setTestNow();
    }
})->skip(
    fn (): bool => ! function_exists('pcntl_fork') || DB::connection()->getDriverName() === 'sqlite',
    'Needs pcntl and a server database shared by two processes.',
);

/**
 * Runs one worker in the forked child, on a connection of its own, then
 * ends the child without running shutdown code: closing the connection it
 * inherited would close the parent's session too.
 */
function runForkedWorker(string $connectionName): never
{
    $inheritedConnection = DB::connection($connectionName);
    // Kept referenced, so its destructor never closes the parent's socket.
    $GLOBALS['inheritedPdo'] = $inheritedConnection->getPdo();
    $ownConnection = app('db.factory')->make(config("database.connections.{$connectionName}"), $connectionName);
    $inheritedConnection->setPdo($ownConnection->getPdo());
    $inheritedConnection->setReadPdo($ownConnection->getPdo());

    // A few rounds: with one slot per endpoint, the other worker's lease
    // holds back the second delivery of an endpoint until its send ends.
    for ($round = 0; $round < 15; $round++) {
        app(WebhookDeliveryCoordinator::class)->work(50);
        usleep(100_000);
    }

    posix_kill(posix_getpid(), SIGKILL);

    exit(1);
}
