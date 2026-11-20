<?php

declare(strict_types=1);

namespace Qiick\Outbound\Tests\Support;

use Qiick\Outbound\Retry\Randomizer;

/**
 * Deterministic {@see Randomizer} that yields a fixed sequence of integers.
 */
final class FixedRandomizer implements Randomizer
{
    /** @param list<int> $values */
    public function __construct(
        private array $values,
    ) {}

    public function intBetween(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Randomizer minimum must not exceed maximum.');
        }

        if ($this->values === []) {
            throw new \RuntimeException('FixedRandomizer has no remaining values.');
        }

        $value = array_shift($this->values);

        if ($value < $min || $value > $max) {
            throw new \RuntimeException(sprintf(
                'FixedRandomizer value %d is outside [%d, %d].',
                $value,
                $min,
                $max,
            ));
        }

        return $value;
    }
}
