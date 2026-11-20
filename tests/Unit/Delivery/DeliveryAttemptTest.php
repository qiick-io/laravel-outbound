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

it('strips C0 controls and caps errorMessage length on a byte boundary', function (): void {
    $long = str_repeat('a', DeliveryAttempt::MAX_ERROR_MESSAGE_BYTES + 50);
    $attempt = new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        500,
        "boom\n\tok\x00".$long,
        new DateTimeImmutable('@1'),
        null,
    );

    expect($attempt->errorMessage)->not->toContain("\n")
        ->and($attempt->errorMessage)->not->toContain("\t")
        ->and($attempt->errorMessage)->not->toContain("\0")
        ->and(strlen((string) $attempt->errorMessage))->toBe(DeliveryAttempt::MAX_ERROR_MESSAGE_BYTES)
        ->and($attempt->errorMessage)->toStartWith('boomok');
});

it('caps with mb_strcut so a multi-byte character is never split', function (): void {
    // Euro sign is 3 bytes in UTF-8; fill so substr would cut mid-character.
    $euro = "\u{20AC}";
    $prefix = str_repeat('a', DeliveryAttempt::MAX_ERROR_MESSAGE_BYTES - 1);
    $attempt = new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        500,
        $prefix.$euro.$euro,
        new DateTimeImmutable('@1'),
        null,
    );

    $msg = (string) $attempt->errorMessage;
    // substr() would leave a dangling euro lead byte (invalid UTF-8); mb_strcut must not.
    expect(strlen($msg))->toBeLessThanOrEqual(DeliveryAttempt::MAX_ERROR_MESSAGE_BYTES)
        ->and(mb_check_encoding($msg, 'UTF-8'))->toBeTrue()
        ->and($msg)->toBe(str_repeat('a', DeliveryAttempt::MAX_ERROR_MESSAGE_BYTES - 1));
});

it('strips U+2028 and U+2029 line/paragraph separators', function (): void {
    $attempt = new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        500,
        "a\u{2028}b\u{2029}c",
        new DateTimeImmutable('@1'),
        null,
    );

    expect($attempt->errorMessage)->toBe('abc');
});

it('strips C1 controls U+0080–U+009F', function (): void {
    $attempt = new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        500,
        "x\u{0085}y\u{009F}z",
        new DateTimeImmutable('@1'),
        null,
    );

    expect($attempt->errorMessage)->toBe('xyz');
});

it('strips bidi control characters', function (): void {
    $attempt = new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        500,
        "a\u{202E}b\u{2066}c\u{2069}d",
        new DateTimeImmutable('@1'),
        null,
    );

    expect($attempt->errorMessage)->toBe('abcd');
});

it('scrubs invalid UTF-8 instead of returning an empty string', function (): void {
    $invalid = "ok\x80\xFFend";
    $attempt = new DeliveryAttempt(
        'evt',
        'type',
        1,
        DeliveryStatus::Failed,
        500,
        $invalid,
        new DateTimeImmutable('@1'),
        null,
    );

    expect($attempt->errorMessage)->not->toBe('')
        ->and($attempt->errorMessage)->not->toBeNull()
        ->and(mb_check_encoding((string) $attempt->errorMessage, 'UTF-8'))->toBeTrue()
        ->and($attempt->errorMessage)->toContain('ok')
        ->and($attempt->errorMessage)->toContain('end');
});
