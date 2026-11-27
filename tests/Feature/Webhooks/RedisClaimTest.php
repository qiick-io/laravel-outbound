<?php

declare(strict_types=1);

use Qiick\Outbound\Webhooks\WebhookIdClaim;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/redis-claim-worker.php';

it('grants one scoped claim to concurrent Redis workers and only releases the winning token (D4)', function (): void {
    $port = (int) getenv('OUTBOUND_TEST_REDIS_PORT');
    $scope = 'claim-proof-'.bin2hex(random_bytes(8));
    $store = outboundRedisClaimStore($port);
    $workers = [];
    $inputs = [];

    try {
        for ($worker = 0; $worker < 4; $worker++) {
            $input = new InputStream;
            $process = new Process([PHP_BINARY, __DIR__.'/../../Support/redis-claim-worker.php', (string) $port, $scope], timeout: 15);
            $process->setInput($input);
            $process->start();
            $workers[] = $process;
            $inputs[] = $input;
        }

        foreach ($workers as $process) {
            expect($process->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, 'ready')))->toBeTrue();
        }

        foreach ($inputs as $input) {
            $input->write("start\n");
            $input->close();
        }

        $claims = ['won' => [], 'lost' => []];

        foreach ($workers as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            [, $result] = explode("\n", trim($process->getOutput()));
            [$outcome, $token] = explode(' ', $result);
            $claims[$outcome][] = new WebhookIdClaim($scope, 'msg_fixture', $token);
        }

        expect($claims['won'])->toHaveCount(1)
            ->and($claims['lost'])->toHaveCount(3);

        $store->release($claims['lost'][0]);
        $replacement = WebhookIdClaim::generate($scope, 'msg_fixture');
        expect($store->claim($replacement, 60))->toBeFalse();

        $store->release($claims['won'][0]);
        expect($store->claim($replacement, 60))->toBeTrue();
        $store->release($replacement);
    } finally {
        foreach ($workers as $process) {
            $process->stop();
        }
    }
})->group('redis-claim')->skip(getenv('OUTBOUND_TEST_REDIS_PORT') === false, 'Needs the disposable Redis service.');
