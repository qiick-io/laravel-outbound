<?php

declare(strict_types=1);

it('merges the package defaults into the outbound config', function (): void {
    expect(config('outbound'))->toMatchArray([
        'ssrf' => [
            'allow_private_hosts' => false,
            'https_only' => false,
        ],
        'http' => [
            'timeout_seconds' => 5,
            'connect_timeout_seconds' => 3,
            'max_response_bytes' => 1_048_576,
            'max_redirects' => 0,
        ],
        'webhooks' => [
            'replay_tolerance_seconds' => 300,
            'max_payload_bytes' => 1_048_576,
            'max_signatures' => 8,
            'max_secrets' => 4,
            'retry_preset' => 'standard',
            'retry_jitter_seconds' => 30,
            'allow_raw_legacy_secret' => false,
        ],
    ]);
});

it('lets an app publish the config file', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'outbound-config', '--force' => true])
        ->assertSuccessful();

    expect(config_path('outbound.php'))->toBeFile();

    unlink(config_path('outbound.php'));
});
