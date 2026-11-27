<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Qiick\Outbound\Tests\Support\PackageTestCase;

/*
 * Fleet convention F9: the package schedules its own prune, daily on one
 * server, unless the product turns `outbound.webhooks.schedule` off to
 * schedule it differently.
 */

/**
 * @return list<ScheduledEvent>
 */
function scheduledPrunes(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'outbound:prune-deliveries'),
    ));
}

it('schedules the prune daily, on one server, without overlapping', function (): void {
    $prunes = scheduledPrunes();

    expect($prunes)->toHaveCount(1)
        ->and($prunes[0]->expression)->toBe('0 0 * * *')
        ->and($prunes[0]->onOneServer)->toBeTrue()
        ->and($prunes[0]->withoutOverlapping)->toBeTrue();
});

it('schedules nothing when the product turns the schedule off', function (): void {
    /** @var PackageTestCase $this */
    $this->configBeforeBoot = ['outbound.webhooks.schedule' => false];
    $this->refreshApplication();

    expect(scheduledPrunes())->toBe([]);

    $this->configBeforeBoot = [];
    $this->refreshApplication();
});
