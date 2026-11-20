<?php

declare(strict_types=1);

use Qiick\Outbound\Tests\Support\PackageTestCase;

pest()->extend(PackageTestCase::class)->in('Feature', 'Unit', 'Arch');
