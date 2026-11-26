<?php

declare(strict_types=1);

use Qiick\Outbound\Tests\Support\PackageTestCase;

require __DIR__.'/Support/SsrfHelpers.php';

pest()->extend(PackageTestCase::class)->in('Feature', 'Unit', 'Arch', 'Erasure');
