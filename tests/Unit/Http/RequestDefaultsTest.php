<?php

declare(strict_types=1);

use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpLimits;

/*
 * The defaults are part of the contract: a product that passes nothing gets
 * these limits.
 */

it('defaults a request to 5 s, 3 s to connect, no redirects and 1 MiB', function (): void {
    $options = new HttpRequestOptions;

    expect($options->timeoutSeconds)->toBe(5)
        ->and($options->connectTimeoutSeconds)->toBe(3)
        ->and($options->followRedirects)->toBeFalse()
        ->and($options->maxRedirects)->toBe(0)
        ->and($options->maxResponseBytes)->toBe(1_048_576)
        ->and($options->headers)->toBe([])
        ->and($options->allowCrossOriginRedirects)->toBeFalse();
});

it('caps a request at 60 s, 30 s to connect and a 30 s total deadline by default', function (): void {
    $limits = new OutboundHttpLimits;

    expect($limits->maxTimeoutSeconds)->toBe(60)
        ->and($limits->maxConnectTimeoutSeconds)->toBe(30)
        ->and($limits->totalDeadlineSeconds)->toBe(30);
});
