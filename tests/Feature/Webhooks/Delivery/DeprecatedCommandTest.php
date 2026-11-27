<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

it('accepts the deprecated worker command with its original options and logs the replacement', function (): void {
    $this->freezeSecond();
    Log::spy();

    $this->artisan('outbound:webhooks:work', ['--limit' => 1, '--max-seconds' => 1])
        ->expectsOutput('Sent 0 webhook delivery attempt(s).')
        ->assertSuccessful();
    expect(Cache::get('outbound:deprecated-work-command'))->toBeTrue();
    Log::shouldHaveReceived('warning')->with('outbound:webhooks:work is deprecated. Use outbound:work-deliveries.')->once();

    $this->travel(3599)->seconds();
    $this->artisan('outbound:webhooks:work')->assertSuccessful();
    Log::shouldHaveReceived('warning')->with('outbound:webhooks:work is deprecated. Use outbound:work-deliveries.')->once();

    $this->travel(1)->seconds();
    $this->artisan('outbound:webhooks:work')->assertSuccessful();

    expect(Artisan::all()['outbound:webhooks:work']->isHidden())->toBeTrue();
    Log::shouldHaveReceived('warning')->with('outbound:webhooks:work is deprecated. Use outbound:work-deliveries.')->twice();
});

it('accepts the deprecated prune command and its dry-run option and logs the replacement', function (): void {
    Log::spy();

    $this->artisan('outbound:webhooks:prune', ['--dry-run' => true])
        ->expectsOutput('Would prune 0 webhook delivery bodies and delete 0 webhook deliveries (dry run, nothing changed).')
        ->assertSuccessful();

    expect(Artisan::all()['outbound:webhooks:prune']->isHidden())->toBeTrue();
    Log::shouldHaveReceived('warning')->with('outbound:webhooks:prune is deprecated. Use outbound:prune-deliveries.')->once();
});

it('still runs the deprecated worker while the warning cache is unavailable', function (): void {
    Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('fixture cache error'));

    $this->artisan('outbound:webhooks:work')
        ->expectsOutput('Sent 0 webhook delivery attempt(s).')
        ->assertSuccessful();
});
