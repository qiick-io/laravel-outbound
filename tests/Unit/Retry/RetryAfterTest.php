<?php

declare(strict_types=1);

use Qiick\Outbound\Retry\RetryAfter;
use Qiick\Outbound\Retry\RetrySchedule;

/*
 * RFC 9110 §10.2.3 (Retry-After = HTTP-date / delay-seconds) and §5.6.7 (a
 * recipient MUST accept all three HTTP-date formats).
 */

function retryAfterNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-23 10:00:00', new DateTimeZone('UTC'));
}

it('reads delay-seconds', function (string $headerValue, int $expectedSeconds): void {
    expect(RetryAfter::parse($headerValue, retryAfterNow()))->toBe($expectedSeconds);
})->with([
    'zero' => ['0', 0],
    'plain' => ['120', 120],
    'leading zeros' => ['0042', 42],
    'more leading zeros than the cap has digits' => ['00000000120', 120],
    'surrounding whitespace' => [" 120\t", 120],
    'one under the cap' => ['2591999', 2_591_999],
    'at the cap' => ['2592000', RetrySchedule::maxDelaySeconds()],
    'above the cap' => ['2592001', RetrySchedule::maxDelaySeconds()],
    'beyond PHP_INT_MAX' => ['99999999999999999999999', RetrySchedule::maxDelaySeconds()],
    'beyond the float range' => ['1'.str_repeat('0', 400), RetrySchedule::maxDelaySeconds()],
]);

it('reads the three HTTP-date formats as seconds from now', function (string $headerValue): void {
    expect(RetryAfter::parse($headerValue, retryAfterNow()))->toBe(3_723);
})->with([
    'IMF-fixdate' => ['Mon, 23 Nov 2026 11:02:03 GMT'],
    'obsolete RFC 850' => ['Monday, 23-Nov-26 11:02:03 GMT'],
    'obsolete asctime, two-digit day' => ['Mon Nov 23 11:02:03 2026'],
]);

it('reads an asctime date with a space-padded day', function (): void {
    $now = new DateTimeImmutable('2026-11-01 10:00:00', new DateTimeZone('UTC'));

    expect(RetryAfter::parse('Tue Nov  3 10:00:00 2026', $now))->toBe(172_800);
});

it('ignores the weekday name, which RFC 9110 does not ask a recipient to check', function (): void {
    expect(RetryAfter::parse('Fri, 23 Nov 2026 10:00:10 GMT', retryAfterNow()))->toBe(10);
});

it('reads every month name', function (string $monthName, int $monthNumber): void {
    $now = new DateTimeImmutable("2026-{$monthNumber}-01 00:00:00", new DateTimeZone('UTC'));

    expect(RetryAfter::parse("Thu, 01 {$monthName} 2026 00:00:05 GMT", $now))->toBe(5);
})->with([
    ['Jan', 1], ['Feb', 2], ['Mar', 3], ['Apr', 4], ['May', 5], ['Jun', 6],
    ['Jul', 7], ['Aug', 8], ['Sep', 9], ['Oct', 10], ['Nov', 11], ['Dec', 12],
]);

it('reads a two-digit RFC 850 year as the latest year not more than 50 years ahead', function (string $headerValue, int $expectedSeconds): void {
    expect(RetryAfter::parse($headerValue, retryAfterNow()))->toBe($expectedSeconds);
})->with([
    // 2076 is 50 years ahead, so it stays in this century (and hits the cap).
    'fifty years ahead' => ['Monday, 23-Nov-76 10:00:00 GMT', RetrySchedule::maxDelaySeconds()],
    // 2077 would be more than 50 years ahead: it means 1977, in the past.
    'more than fifty years ahead' => ['Tuesday, 23-Nov-77 10:00:00 GMT', 0],
]);

it('returns zero for a date in the past or exactly now', function (string $headerValue): void {
    expect(RetryAfter::parse($headerValue, retryAfterNow()))->toBe(0);
})->with([
    'past' => ['Sun, 06 Nov 1994 08:49:37 GMT'],
    'now' => ['Mon, 23 Nov 2026 10:00:00 GMT'],
    'one second ago' => ['Mon, 23 Nov 2026 09:59:59 GMT'],
]);

it('caps a far-future date like a large delay', function (): void {
    expect(RetryAfter::parse('Fri, 31 Dec 9999 23:59:59 GMT', retryAfterNow()))->toBe(RetrySchedule::maxDelaySeconds());
});

it('rounds a fractional "now" up so the wait is never shortened', function (): void {
    $now = new DateTimeImmutable('2026-11-23 10:00:00.250', new DateTimeZone('UTC'));

    expect(RetryAfter::parse('Mon, 23 Nov 2026 10:00:10 GMT', $now))->toBe(10);
});

it('measures from now in any time zone', function (): void {
    $now = new DateTimeImmutable('2026-11-23 11:00:00', new DateTimeZone('Europe/Rome'));

    expect(RetryAfter::parse('Mon, 23 Nov 2026 10:00:30 GMT', $now))->toBe(30);
});

it('refuses malformed values, so the plain schedule applies', function (string $headerValue): void {
    expect(RetryAfter::parse($headerValue, retryAfterNow()))->toBeNull();
})->with([
    'empty' => [''],
    'delay with a trailing newline' => ["120\n"],
    'date with a trailing newline' => ["Mon, 23 Nov 2026 11:02:03 GMT\n"],
    'blank' => ['   '],
    'negative' => ['-5'],
    'fraction' => ['1.5'],
    'signed' => ['+5'],
    'words' => ['soon'],
    'digits then text' => ['120s'],
    'lower-case IMF' => ['mon, 23 nov 2026 11:02:03 gmt'],
    'not GMT' => ['Mon, 23 Nov 2026 11:02:03 UTC'],
    'one-digit IMF day' => ['Mon, 3 Nov 2026 11:02:03 GMT'],
    'unknown month' => ['Mon, 23 Foo 2026 11:02:03 GMT'],
    'unknown weekday' => ['Xyz, 23 Nov 2026 11:02:03 GMT'],
    'RFC 850 short weekday' => ['Mon, 23-Nov-26 11:02:03 GMT'],
    'asctime with GMT' => ['Mon Nov 23 11:02:03 2026 GMT'],
    'no such day' => ['Mon, 31 Nov 2026 11:02:03 GMT'],
    'February 29 in a common year' => ['Sun, 29 Feb 2026 00:00:00 GMT'],
    'hour 24' => ['Mon, 23 Nov 2026 24:00:00 GMT'],
    'minute 60' => ['Mon, 23 Nov 2026 11:60:00 GMT'],
    'second 60' => ['Mon, 23 Nov 2026 11:02:60 GMT'],
    'trailing text' => ['Mon, 23 Nov 2026 11:02:03 GMT x'],
    'leading text' => ['x Mon, 23 Nov 2026 11:02:03 GMT'],
    'two dates' => ['Mon, 23 Nov 2026 11:02:03 GMT, Mon, 23 Nov 2026 11:02:03 GMT'],
    'day zero' => ['Mon, 00 Nov 2026 11:02:03 GMT'],
]);

it('accepts February 29 in a leap year', function (): void {
    $now = new DateTimeImmutable('2028-02-29 00:00:00', new DateTimeZone('UTC'));

    expect(RetryAfter::parse('Tue, 29 Feb 2028 00:00:01 GMT', $now))->toBe(1);
});
