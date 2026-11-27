<?php

declare(strict_types=1);

use Illuminate\Cache\DatabaseLock;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\DatabaseWebhookIdLock;
use Qiick\Outbound\Webhooks\DuplicateWebhookIdException;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookIdClaim;
use Qiick\Outbound\Webhooks\WebhookSecret;

it('uses the native database lock outside a transaction', function (bool $acquired): void {
    $connection = Mockery::mock(Connection::class);
    $connection->expects('transactionLevel')->andReturn(0);
    $lock = Mockery::mock(DatabaseLock::class, [$connection, 'cache_locks', 'test-lock', 60, 'test-owner'])
        ->makePartial();
    $lock->expects('get')->andReturn($acquired);

    expect(DatabaseWebhookIdLock::acquireClaim($lock))->toBe($acquired);
})->with([true, false]);

it('reports a duplicate without aborting the calling database transaction', function (): void {
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.lock_lottery', [1, 1]);
    $verifier = app(StandardWebhookVerifier::class);
    $signer = app(StandardWebhookSigner::class);
    $secret = WebhookSecret::generate();
    $now = new DateTimeImmutable;
    $signed = $signer->sign('msg_1', $now->getTimestamp(), '{}', [$secret]);
    $scope = 'transaction-'.bin2hex(random_bytes(8));
    $verify = static fn (): WebhookIdClaim => $verifier->verify(
        $signed->rawBody, 'msg_1', (string) $now->getTimestamp(), $signed->headers['webhook-signature'], [$secret], $now, $scope,
    );

    DB::transaction(function () use ($verify): void {
        DB::table('cache_locks')->insert(['key' => 'old-fixture', 'owner' => 'old-owner', 'expiration' => 1]);
        expect($verify())->toBeInstanceOf(WebhookIdClaim::class)
            ->and($verify)->toThrow(DuplicateWebhookIdException::class)
            ->and(DB::table('cache_locks')->where('key', 'old-fixture')->exists())->toBeFalse()
            ->and((int) DB::selectOne('select 1 as active')->active)->toBe(1);
    });
});

it('preserves a replacement database claim and deletes with an owner predicate', function (): void {
    $firstWorker = new CacheSeenWebhookIdStore(Cache::store('database'));
    $secondWorker = new CacheSeenWebhookIdStore(Cache::store('database'));
    $scope = 'fixture-'.bin2hex(random_bytes(8));
    $expired = WebhookIdClaim::generate($scope, 'msg_1');
    $replacement = WebhookIdClaim::generate($scope, 'msg_1');
    expect($firstWorker->claim($expired, 1))->toBeTrue();
    $this->travel(2)->seconds();

    try {
        expect($secondWorker->claim($replacement, 60))->toBeTrue();
        $firstWorker->release($expired);
        expect($firstWorker->claim(WebhookIdClaim::generate($scope, 'msg_1'), 60))->toBeFalse();

        $deletes = [];
        DB::listen(static function (QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with(strtolower($query->sql), 'delete ')) {
                $deletes[] = $query;
            }
        });

        $secondWorker->release($replacement);

        expect($deletes)->toHaveCount(1)
            ->and($deletes[0]->sql)->toContain('owner')
            ->and($deletes[0]->bindings)->toContain($replacement->token)
            ->and($firstWorker->claim(WebhookIdClaim::generate($scope, 'msg_1'), 60))->toBeTrue();
    } finally {
        $this->travelBack();
    }
});
