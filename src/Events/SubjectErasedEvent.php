<?php

declare(strict_types=1);

namespace Qiick\Outbound\Events;

use Qiick\Outbound\Erasure\ErasureReport;

/**
 * Announces a committed outbound erasure with the aggregate report only:
 * no ids, identifiers or row content.
 *
 * @api
 */
final readonly class SubjectErasedEvent
{
    public function __construct(public ErasureReport $report) {}
}
