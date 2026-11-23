<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses a `Retry-After` header value into whole seconds to wait.
 *
 * RFC 9110 §10.2.3: the value is delay-seconds or an HTTP-date, and §5.6.7: a
 * recipient MUST accept all three HTTP-date formats (IMF-fixdate, the obsolete
 * RFC 850 and asctime forms). Parsing is strict, as the grammar is case
 * sensitive; the weekday name is not checked against the date, which the RFC
 * does not require. Dates are matched by hand because PHP's date parser moves
 * a date to the named weekday instead of refusing or ignoring it.
 *
 * @api
 */
final class RetryAfter
{
    private const string WEEKDAY = '(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)';

    private const string LONG_WEEKDAY = '(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)';

    private const string MONTH = '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';

    private const string TIME = '(\d{2}):(\d{2}):(\d{2})';

    /**
     * @return int|null seconds to wait, from 0 (a date now or in the past) up
     *                  to {@see RetrySchedule::MAX_DELAY_SECONDS}; null when
     *                  the value is malformed, so the plain schedule applies
     */
    public static function parse(string $headerValue, DateTimeImmutable $now): ?int
    {
        $headerValue = trim($headerValue, " \t");

        // D: `$` must not accept a trailing newline.
        if (preg_match('/^\d+$/D', $headerValue) === 1) {
            $digits = ltrim($headerValue, '0');

            // Longer than the cap's own digits is above it; a cast would
            // overflow, and past ~308 digits even become 0 through INF.
            return strlen($digits) > strlen((string) RetrySchedule::MAX_DELAY_SECONDS)
                ? RetrySchedule::MAX_DELAY_SECONDS
                : self::capped((int) $digits);
        }

        $retryAt = self::httpDate($headerValue, $now);

        if ($retryAt === null) {
            return null;
        }

        // Whole seconds: the date has none, and dropping the fraction of
        // "now" rounds the wait up, never down.
        return self::capped(max(0, $retryAt->getTimestamp() - $now->getTimestamp()));
    }

    private static function capped(int $seconds): int
    {
        return min($seconds, RetrySchedule::MAX_DELAY_SECONDS);
    }

    private static function httpDate(string $headerValue, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $time = self::TIME;

        if (preg_match('/^'.self::WEEKDAY.', (\d{2}) '.self::MONTH." (\\d{4}) {$time} GMT$/D", $headerValue, $parts) === 1) {
            return self::utcDate((int) $parts[3], $parts[2], (int) $parts[1], (int) $parts[4], (int) $parts[5], (int) $parts[6]);
        }

        if (preg_match('/^'.self::LONG_WEEKDAY.', (\d{2})-'.self::MONTH."-(\\d{2}) {$time} GMT$/D", $headerValue, $parts) === 1) {
            $date = self::utcDate(self::fullYear($parts[3], $now), $parts[2], (int) $parts[1], (int) $parts[4], (int) $parts[5], (int) $parts[6]);

            // RFC 9110 §5.6.7: a two-digit year that appears to be more than 50
            // years ahead means the most recent past year with those digits.
            if ($date !== null && $date > $now->modify('+50 years')) {
                return $date->modify('-100 years');
            }

            return $date;
        }

        if (preg_match('/^'.self::WEEKDAY.' '.self::MONTH." ( \\d|\\d{2}) {$time} (\\d{4})$/D", $headerValue, $parts) === 1) {
            // A space-padded day (" 3") casts like "3".
            return self::utcDate((int) $parts[6], $parts[1], (int) $parts[2], (int) $parts[3], (int) $parts[4], (int) $parts[5]);
        }

        return null;
    }

    /**
     * The two digits in the current century; the caller moves a date too far
     * ahead back by a century.
     */
    private static function fullYear(string $twoDigitYear, DateTimeImmutable $now): int
    {
        return (int) (substr($now->format('Y'), 0, -2).$twoDigitYear);
    }

    /**
     * The month name has passed the regex, so it is one of the twelve.
     */
    private static function utcDate(int $year, string $monthName, int $day, int $hour, int $minute, int $second): ?DateTimeImmutable
    {
        // @phpstan-ignore match.unhandled (the HTTP-date regexes admit only these twelve names)
        $month = match ($monthName) {
            'Jan' => 1,
            'Feb' => 2,
            'Mar' => 3,
            'Apr' => 4,
            'May' => 5,
            'Jun' => 6,
            'Jul' => 7,
            'Aug' => 8,
            'Sep' => 9,
            'Oct' => 10,
            'Nov' => 11,
            'Dec' => 12,
        };

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return (new DateTimeImmutable('@0'))
            ->setTimezone(new DateTimeZone('UTC'))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second);
    }
}
