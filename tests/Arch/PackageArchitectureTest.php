<?php

declare(strict_types=1);

/**
 * Package-only architecture rules. Naming, suffixes, strict types and readonly
 * DTOs are the shared qiick rules in NamingArchitectureTest.php (configured in
 * naming-architecture.php).
 */
arch('the Laravel namespace only holds the service provider')
    ->expect('Qiick\Outbound\Laravel')
    ->toHaveSuffix('ServiceProvider');

arch('test doubles shipped for apps start with Fake')
    ->expect('Qiick\Outbound\Testing')
    ->toHavePrefix('Fake');
