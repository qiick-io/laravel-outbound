<?php

declare(strict_types=1);

namespace Qiick\Outbound\Ssrf;

use RuntimeException;

/**
 * Thrown when RemoteUrlGuard refuses a URL.
 *
 * @api
 */
final class UnsafeUrl extends RuntimeException
{
    public function __construct(
        private readonly string $refusalReason,
    ) {
        parent::__construct($refusalReason);
    }

    public function reason(): string
    {
        return $this->refusalReason;
    }
}
