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

it('snapshots each body type with its content type, the last one set replacing the others', function (Closure $setBody, array $expectedBody): void {
    $request = (new OutboundRequest)->asJson(['earlier' => true]);
    $setBody($request);

    expect($request->snapshot()['body'])->toBe($expectedBody);
})->with([
    'raw with a content type' => [fn (OutboundRequest $request) => $request->withBody('bytes', 'text/plain'), ['type' => 'raw', 'payload' => 'bytes', 'contentType' => 'text/plain']],
    'raw without one' => [fn (OutboundRequest $request) => $request->withBody('bytes'), ['type' => 'raw', 'payload' => 'bytes', 'contentType' => null]],
    'json' => [fn (OutboundRequest $request) => $request->withBody('x')->asJson(['a' => 1]), ['type' => 'json', 'payload' => ['a' => 1], 'contentType' => 'application/json']],
    'form' => [fn (OutboundRequest $request) => $request->asForm(['a' => 'b']), ['type' => 'form', 'payload' => ['a' => 'b'], 'contentType' => 'application/x-www-form-urlencoded']],
    'multipart' => [fn (OutboundRequest $request) => $request->asForm(['a' => 'b'])->asMultipart([['name' => 'f', 'contents' => 'v']]), ['type' => 'multipart', 'payload' => [['name' => 'f', 'contents' => 'v']], 'contentType' => null]],
]);

it('sets the Content-Type header only for a raw body that names one', function (): void {
    expect((new OutboundRequest)->withBody('bytes', 'text/plain')->snapshot()['headers'])->toBe(['Content-Type' => 'text/plain'])
        ->and((new OutboundRequest)->withBody('bytes')->snapshot()['headers'])->toBe([]);
});

it('accepts a numeric header name and keeps the query as given', function (): void {
    $snapshot = (new OutboundRequest)->withHeaders(['123' => 'v', 'X-Key' => 'k'])->withQuery(['page' => 2, '1' => 'a'])->snapshot();

    expect($snapshot['headers'])->toBe(['123' => 'v', 'X-Key' => 'k'])
        ->and($snapshot['query'])->toBe(['page' => 2, '1' => 'a']);
});

it('rebuilds a request from its snapshot, whatever its body', function (Closure $build): void {
    $request = $build(new OutboundRequest);

    expect(OutboundRequest::fromSnapshot($request->snapshot())->snapshot())->toBe($request->snapshot());
})->with([
    'no body' => [fn (OutboundRequest $request) => $request->withHeader('X-Key', 'k')->withQuery(['q' => 'v'])],
    'raw' => [fn (OutboundRequest $request) => $request->withBody('bytes', 'text/plain')],
    'json' => [fn (OutboundRequest $request) => $request->asJson(['a' => 1])],
    'form' => [fn (OutboundRequest $request) => $request->asForm(['a' => 'b'])],
    'multipart, a list of parts' => [fn (OutboundRequest $request) => $request->asMultipart([['name' => 'f', 'contents' => 'v']])],
]);

it('refuses a snapshot whose body does not match its type', function (array $body, string $message): void {
    expect(fn () => OutboundRequest::fromSnapshot(['headers' => [], 'query' => [], 'body' => $body]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'raw, not a string' => [['type' => 'raw', 'payload' => ['x'], 'contentType' => null], 'Raw body must be a string.'],
    'json, not an array' => [['type' => 'json', 'payload' => 'x', 'contentType' => null], 'JSON body must be an array.'],
    'form, a list' => [['type' => 'form', 'payload' => ['x'], 'contentType' => null], 'Form body keys must be strings.'],
    'multipart, not a list' => [['type' => 'multipart', 'payload' => ['f' => 'v'], 'contentType' => null], 'Multipart body must be a list of parts.'],
    'multipart, a part that is not an array' => [['type' => 'multipart', 'payload' => ['v'], 'contentType' => null], 'Multipart part body must be an array.'],
    'an unknown type' => [['type' => 'xml', 'payload' => '<x/>', 'contentType' => null], 'Unknown outbound body type.'],
]);
