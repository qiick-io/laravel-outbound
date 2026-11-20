<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

/**
 * Injectable integer source for retry jitter (tests use a fixed sequence).
 *
 * @api
 */
interface Randomizer
{
    /**
     * @throws \InvalidArgumentException when $min > $max
     */
    public function intBetween(int $min, int $max): int;
}
