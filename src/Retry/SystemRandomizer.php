<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

/**
 * Cryptographically secure {@see Randomizer} backed by {@see random_int()}.
 *
 * @api
 */
final class SystemRandomizer implements Randomizer
{
    public function intBetween(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Randomizer minimum must not exceed maximum.');
        }

        return random_int($min, $max);
    }
}
