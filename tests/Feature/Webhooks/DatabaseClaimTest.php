<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Qiick\Outbound\Webhooks\CacheSeenWebhookIdStore;
use Qiick\Outbound\Webhooks\WebhookIdClaim;

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
