<?php

declare(strict_types=1);

use Qiick\Outbound\Delivery\DeliveryAttempt;
use Qiick\Outbound\Delivery\DeliveryStatus;

it('stores a delivered attempt with http status and no next attempt', function (): void {
    $at = new DateTimeImmutable('@1700000000');
    $attempt = new DeliveryAttempt(
        eventId: 'evt_1',
        eventType: 'order.created',
        attempt: 1,
        status: DeliveryStatus::Delivered,
        httpStatus: 200,
        errorMessage: null,
        at: $at,
        nextAttemptAt: null,
    );

    expect($attempt->eventId)->toBe('evt_1')
        ->and($attempt->eventType)->toBe('order.created')
        ->and($attempt->attempt)->toBe(1)
        ->and($attempt->status)->toBe(DeliveryStatus::Delivered)
        ->and($attempt->httpStatus)->toBe(200)
        ->and($attempt->errorMessage)->toBeNull()
        ->and($attempt->at)->toBe($at)
        ->and($attempt->nextAttemptAt)->toBeNull();
});

it('stores a failed attempt with nextAttemptAt', function (): void {
    $at = new DateTimeImmutable('@1700000000');
    $next = new DateTimeImmutable('@1700000300');
    $attempt = new DeliveryAttempt(
        'evt_2',
        'invoice.paid',
        2,
        DeliveryStatus::Failed,
        503,
        'upstream unavailable',
        $at,
        $next,
    );

    expect($attempt->status)->toBe(DeliveryStatus::Failed)
        ->and($attempt->nextAttemptAt)->toBe($next)
        ->and($attempt->errorMessage)->toBe('upstream unavailable');
});

it('stores a refused attempt without http status', function (): void {
    $attempt = new DeliveryAttempt(
        'evt_3',
        'ping',
        1,
        DeliveryStatus::Refused,
        null,
        'ssrf blocked',
        new DateTimeImmutable('@1'),
        null,
    );

    expect($attempt->status)->toBe(DeliveryStatus::Refused)
        ->and($attempt->httpStatus)->toBeNull();
});

it('refuses empty event id and event type', function (string $eventId, string $eventType): void {
    expect(fn () => new DeliveryAttempt(
        $eventId,
        $eventType,
        1,
        DeliveryStatus::Failed,
        null,
        null,
        new DateTimeImmutable('@1'),
        null,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'empty id' => ['', 'type'],
    'empty type' => ['id', ''],
]);

it('refuses non-positive attempt numbers', function (int $attempt): void {
    expect(fn () => new DeliveryAttempt(
        'evt',
        'type',
        $attempt,
        DeliveryStatus::Failed,
        null,
        null,
        new DateTimeImmutable('@1'),
        null,
    ))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('exposes backed enum values for storage', function (): void {
    expect(DeliveryStatus::Delivered->value)->toBe('delivered')
        ->and(DeliveryStatus::Failed->value)->toBe('failed')
        ->and(DeliveryStatus::Refused->value)->toBe('refused');
});

it('refuses http statuses outside 100–599', function (int $status): void {
    expect(fn () => new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        $status,
        null,
        new DateTimeImmutable('@1'),
        null,
    ))->toThrow(InvalidArgumentException::class, 'http status must be between 100 and 599');
})->with([99, 600, 0, -1]);

it('refuses nextAttemptAt before at', function (): void {
    $at = new DateTimeImmutable('@100');
    $next = new DateTimeImmutable('@99');

    expect(fn () => new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        503,
        'upstream',
        $at,
        $next,
    ))->toThrow(InvalidArgumentException::class, 'nextAttemptAt must not be before at');
});
