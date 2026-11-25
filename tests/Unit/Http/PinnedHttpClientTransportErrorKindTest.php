<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Http\Message\RequestInterface;
use Qiick\Outbound\Enums\OutboundTransportErrorKindEnum;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeHostResolver;

/*
 * A webhook attempt records why the transport failed (standards review,
 * attempt `error_code`), so the client says which kind of transport error it
 * was. The curl errno decides; the message never carries the host, the
 * pinned address or the URL.
 */

/**
 * @param  callable(RequestInterface): mixed  $rejection
 */
function sendThroughFailingTransport(callable $rejection, ?OutboundHttpLimits $limits = null): OutboundHttpException
{
    $client = new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['example.test' => ['10.20.30.40']]), allowPrivateHosts: true),
        $limits ?? new OutboundHttpLimits,
        null,
        static fn (RequestInterface $request, array $options) => PromiseCreate::rejectionFor($rejection($request)),
    );

    try {
        $client->send('GET', 'https://example.test/secret?token=abc', static function (): void {});
    } catch (OutboundHttpException $exception) {
        return $exception;
    }

    throw new RuntimeException('The transport error was not raised.');
}

it('names the kind of transport error from the curl errno', function (int $curlErrno, OutboundTransportErrorKindEnum $kind, string $message): void {
    $exception = sendThroughFailingTransport(static fn (RequestInterface $request) => new ConnectException(
        "cURL error {$curlErrno}: failure for https://example.test/secret?token=abc (10.20.30.40)",
        $request,
        null,
        ['errno' => $curlErrno],
    ));

    expect($exception->transportErrorKind())->toBe($kind)
        ->and($exception->getMessage())->toBe($message)
        ->and($exception->getPrevious()?->getMessage())->toBe('Underlying transport error.')
        ->and($exception->getPrevious()?->getPrevious())->toBeNull();
})->with([
    'timeout (28)' => [28, OutboundTransportErrorKindEnum::Timeout, 'Outbound request failed: timeout.'],
    'resolve (6)' => [6, OutboundTransportErrorKindEnum::Dns, 'Outbound request failed: DNS error.'],
    'SSL connect (35)' => [35, OutboundTransportErrorKindEnum::Tls, 'Outbound request failed: TLS error.'],
    'peer verification (51)' => [51, OutboundTransportErrorKindEnum::Tls, 'Outbound request failed: TLS error.'],
    'client certificate (58)' => [58, OutboundTransportErrorKindEnum::Tls, 'Outbound request failed: TLS error.'],
    'cipher (59)' => [59, OutboundTransportErrorKindEnum::Tls, 'Outbound request failed: TLS error.'],
    'CA certificate (60)' => [60, OutboundTransportErrorKindEnum::Tls, 'Outbound request failed: TLS error.'],
    'CA bundle (77)' => [77, OutboundTransportErrorKindEnum::Tls, 'Outbound request failed: TLS error.'],
    'certificate status (91)' => [91, OutboundTransportErrorKindEnum::Tls, 'Outbound request failed: TLS error.'],
    'refused (7)' => [7, OutboundTransportErrorKindEnum::Connection, 'Outbound request failed: connection error.'],
    'empty reply (52)' => [52, OutboundTransportErrorKindEnum::Connection, 'Outbound request failed: connection error.'],
    'receive (56)' => [56, OutboundTransportErrorKindEnum::Connection, 'Outbound request failed: connection error.'],
]);

it('reads the errno from the message, which Guzzle 7 and 8 both write', function (): void {
    $exception = sendThroughFailingTransport(static fn (RequestInterface $request) => new ConnectException(
        'cURL error 60: SSL certificate problem for https://example.test/',
        $request,
    ));

    expect($exception->transportErrorKind())->toBe(OutboundTransportErrorKindEnum::Tls);
});

it('classifies a curl error raised after the response started', function (): void {
    $exception = sendThroughFailingTransport(static fn (RequestInterface $request) => new RequestException(
        'cURL error 28: Operation timed out',
        $request,
    ));

    expect($exception->transportErrorKind())->toBe(OutboundTransportErrorKindEnum::Timeout);
});

it('calls an error without a curl errno a connection error', function (string $message): void {
    $exception = sendThroughFailingTransport(static fn (RequestInterface $request) => new ConnectException($message, $request));

    expect($exception->transportErrorKind())->toBe(OutboundTransportErrorKindEnum::Connection)
        ->and($exception->getMessage())->toBe('Outbound request failed: connection error.');
})->with(['socket closed', 'cURL error: none', 'cURL error x28: odd']);

it('never puts the host, the pinned address or the URL in the message', function (int $curlErrno): void {
    $exception = sendThroughFailingTransport(static fn (RequestInterface $request) => new ConnectException(
        "cURL error {$curlErrno}: Failed to connect to example.test (10.20.30.40) for https://example.test/secret?token=abc",
        $request,
        null,
        ['errno' => $curlErrno],
    ));
    $everyMessage = '';

    for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
        $everyMessage .= $link->getMessage()."\n";
    }

    expect($everyMessage)->not->toContain('10.20.30.40')
        ->not->toContain('example.test')
        ->not->toContain('token=abc');
})->with([6, 7, 28, 35]);

it('calls the exhausted chain deadline a timeout', function (): void {
    $ticks = [100.0, 100.5];
    $client = new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']])),
        new OutboundHttpLimits(totalDeadlineSeconds: 1),
        static function () use (&$ticks): float {
            return array_shift($ticks) ?? 100.5;
        },
    );

    try {
        $client->send('GET', 'https://example.test/', static function (): void {});
        $exception = null;
    } catch (OutboundHttpException $caught) {
        $exception = $caught;
    }

    expect($exception?->getMessage())->toBe('Outbound request total deadline exceeded.')
        ->and($exception?->transportErrorKind())->toBe(OutboundTransportErrorKindEnum::Timeout);
});

it('has no transport kind for refusals that are not transport errors', function (): void {
    $client = new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard(new FakeHostResolver(answers: ['example.test' => ['93.184.216.34']])),
        new OutboundHttpLimits,
        null,
        static fn (RequestInterface $request, array $options) => PromiseCreate::promiseFor(new Psr7Response(200, ['Content-Length' => '100'], str_repeat('a', 100))),
    );

    try {
        $client->send('GET', 'https://example.test/', static function (): void {}, new HttpRequestOptions(maxResponseBytes: 10));
        $exception = null;
    } catch (OutboundHttpException $caught) {
        $exception = $caught;
    }

    expect($exception?->getMessage())->toBe('Response exceeds max_response_bytes.')
        ->and($exception?->transportErrorKind())->toBeNull()
        ->and((new OutboundHttpException('Redirect hop limit exceeded.'))->transportErrorKind())->toBeNull();
});
