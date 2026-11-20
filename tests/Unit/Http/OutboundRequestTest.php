<?php

declare(strict_types=1);

use Qiick\Outbound\Http\OutboundRequest;

it('refuses Host and routing headers', function (string $name): void {
    $request = new OutboundRequest;

    expect(fn () => $request->withHeader($name, 'evil.example'))
        ->toThrow(InvalidArgumentException::class, 'Outbound request must not set Host or routing headers.');
})->with([
    'Host',
    'host',
    'HOST',
    'X-Host',
    'X-Original-Host',
    'Forwarded',
    'X-Forwarded-For',
    'X-Forwarded-Host',
    'X-Forwarded-Proto',
    'x-forwarded-server',
    'X-Original-URL',
    'X-Rewrite-URL',
]);

it('accepts ordinary headers and Accept helpers', function (): void {
    $request = (new OutboundRequest)
        ->withHeader('X-Api-Key', 'k')
        ->accept('application/json');

    $snap = $request->snapshot();

    expect($snap['headers']['X-Api-Key'])->toBe('k')
        ->and($snap['headers']['Accept'])->toBe('application/json');
});
