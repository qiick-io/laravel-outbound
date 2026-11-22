<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * Written once by the sync, never overwritten or checked: it is this repo's own.
 * Package-only rules (the Laravel namespace) are in `PackageArchitectureTest.php`.
 */
return [
    'namespace' => 'Qiick\Outbound',

    'ignoring' => [
        'Contracts' => [
            // Binding design API name (laravel-outbound-design.md §3.1).
            'Qiick\Outbound\Contracts\HostResolver',
        ],
    ],

    'suffixes' => [],

    'controllerPublicMethods' => [],
];
