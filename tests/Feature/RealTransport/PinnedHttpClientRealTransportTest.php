<?php

declare(strict_types=1);

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Qiick\Outbound\Enums\OutboundTransportErrorKindEnum;
use Qiick\Outbound\Http\EgressProxy;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\OutboundRequest;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Outbound\Ssrf\UrlGuardOptions;
use Qiick\Outbound\Testing\FakeHostResolver;

/**
 * Real Guzzle+curl transport against php -S. Not Http::fake.
 *
 * @group real-transport
 */
final class RealTransportServer
{
    public string $captureDir;

    public string $pinAddress = '127.0.0.1';

    public int $port;

    /** @var resource */
    public $process;

    public PinnedHttpClient $client;

    public function __construct(?OutboundHttpLimits $limits = null, ?HttpFactory $factory = null)
    {
        $this->captureDir = sys_get_temp_dir().'/outbound-rt-'.bin2hex(random_bytes(8));
        mkdir($this->captureDir, 0700, true);

        $router = dirname(__DIR__, 2).'/Support/real-transport-server.php';
        $this->process = $this->startPhpServer($router);
        $this->waitUntilAcceptingConnections();

        $resolver = (new FakeHostResolver)
            ->with('app.test', [$this->pinAddress])
            ->with('evil.test', [$this->pinAddress]);

        $this->client = new PinnedHttpClient(
            $factory ?? new HttpFactory,
            new RemoteUrlGuard($resolver, allowPrivateHosts: true),
            $limits ?? new OutboundHttpLimits,
        );
    }

    /**
     * @return resource
     */
    private function startPhpServer(string $router)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->captureDir.'/server.out', 'w'],
            2 => ['file', $this->captureDir.'/server.err', 'w'],
        ];

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $port = $this->allocateFreePort();
            $cmd = sprintf(
                'REAL_TRANSPORT_CAPTURE_DIR=%s %s -S %s:%d %s',
                escapeshellarg($this->captureDir),
                escapeshellarg(PHP_BINARY),
                $this->pinAddress,
                $port,
                escapeshellarg($router),
            );

            $pipes = [];
            $process = proc_open($cmd, $descriptors, $pipes);

            if (! is_resource($process)) {
                throw new RuntimeException('Failed to start php -S for real-transport tests.');
            }

            // Brief pause so a bind failure shows up in proc status / stderr.
            usleep(30_000);
            $status = proc_get_status($process);
            $err = @file_get_contents($this->captureDir.'/server.err') ?: '';

            if (($status['running'] ?? false) === true && ! str_contains($err, 'Address already in use')) {
                $this->port = $port;

                return $process;
            }

            proc_terminate($process);
            proc_close($process);
            @unlink($this->captureDir.'/server.err');
            @unlink($this->captureDir.'/server.out');
        }

        throw new RuntimeException('Failed to bind php -S to a free loopback port.');
    }

    private function allocateFreePort(): int
    {
        $socket = @stream_socket_server('tcp://'.$this->pinAddress.':0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException('Failed to allocate a free port: '.$errstr);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name) || ! str_contains($name, ':')) {
            throw new RuntimeException('Failed to read allocated port name.');
        }

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilAcceptingConnections(): void
    {
        $deadline = microtime(true) + 10;
        $lastError = 'timeout';

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (is_array($status) && ($status['running'] ?? false) !== true) {
                $err = @file_get_contents($this->captureDir.'/server.err') ?: '';
                throw new RuntimeException('php -S exited before accepting connections: '.$err);
            }

            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($this->pinAddress, $this->port, $errno, $errstr, 0.25);

            if (is_resource($fp)) {
                fclose($fp);

                // TCP accept is not enough — wait until the router answers HTTP.
                $probe = @file_get_contents(
                    'http://'.$this->pinAddress.':'.$this->port.'/ok',
                    false,
                    stream_context_create([
                        'http' => [
                            'method' => 'GET',
                            'timeout' => 0.5,
                            'header' => "Host: app.test\r\n",
                            'ignore_errors' => true,
                        ],
                    ]),
                );

                if (is_string($probe) && str_contains($probe, 'pinned-ok:')) {
                    return;
                }

                $lastError = 'port open but /ok not ready';
            } else {
                $lastError = $errstr !== '' ? $errstr : 'errno '.$errno;
            }

            usleep(50_000);
        }

        throw new RuntimeException('php -S did not accept connections on '.$this->pinAddress.':'.$this->port.' ('.$lastError.')');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->captureDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->captureDir);
    }

    /**
     * Read a hop capture. When $timeoutSeconds > 0, poll until the atomically
     * published file appears (bounded — no test/CI retries).
     */
    public function capture(string $name, float $timeoutSeconds = 2.0): ?array
    {
        $path = $this->captureDir.'/'.$name.'.json';
        $deadline = microtime(true) + max(0.0, $timeoutSeconds);

        do {
            if (is_file($path)) {
                $raw = @file_get_contents($path);

                if (is_string($raw) && $raw !== '') {
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                }
            }

            if ($timeoutSeconds <= 0.0 || microtime(true) >= $deadline) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function url(string $host, string $path): string
    {
        return 'http://'.$host.':'.$this->port.$path;
    }
}

function realTransportOptions(array $overrides = []): HttpRequestOptions
{
    return new HttpRequestOptions(
        timeoutSeconds: $overrides['timeoutSeconds'] ?? 3,
        connectTimeoutSeconds: $overrides['connectTimeoutSeconds'] ?? 2,
        followRedirects: $overrides['followRedirects'] ?? false,
        maxRedirects: $overrides['maxRedirects'] ?? 0,
        maxResponseBytes: $overrides['maxResponseBytes'] ?? 1_048_576,
        headers: $overrides['headers'] ?? [],
        allowCrossOriginRedirects: $overrides['allowCrossOriginRedirects'] ?? false,
        urlOptions: new UrlGuardOptions(httpsOnly: false),
    );
}

it('connects through real curl to the CONNECT_TO pin and ignores http_proxy', function (): void {
    $server = new RealTransportServer;

    putenv('http_proxy=http://127.0.0.1:9');
    putenv('HTTP_PROXY=http://127.0.0.1:9');
    putenv('https_proxy=http://127.0.0.1:9');
    putenv('HTTPS_PROXY=http://127.0.0.1:9');
    putenv('ALL_PROXY=http://127.0.0.1:9');
    $_ENV['http_proxy'] = 'http://127.0.0.1:9';
    $_ENV['HTTPS_PROXY'] = 'http://127.0.0.1:9';

    try {
        $response = $server->client->send(
            'GET',
            $server->url('app.test', '/ok'),
            static function (OutboundRequest $request): void {},
            realTransportOptions(),
        );

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('pinned-ok:app.test:'.$server->port);

        $capture = $server->capture('ok');
        expect($capture)->not->toBeNull()
            ->and($capture['host'])->toBe('app.test:'.$server->port)
            ->and($capture['path'])->toBe('/ok');
    } finally {
        putenv('http_proxy');
        putenv('HTTP_PROXY');
        putenv('https_proxy');
        putenv('HTTPS_PROXY');
        putenv('ALL_PROXY');
        unset($_ENV['http_proxy'], $_ENV['HTTPS_PROXY']);
        $server->stop();
    }
})->group('real-transport');

it('refuses cross-origin 307 on the real wire even with the opt-in', function (): void {
    $server = new RealTransportServer;

    try {
        expect(fn () => $server->client->send(
            'POST',
            $server->url('app.test', '/redirect-cross'),
            function (OutboundRequest $request): void {
                $request->withBody('super-secret-body', 'text/plain')
                    ->withHeader('Authorization', 'Bearer real-leak');
            },
            realTransportOptions([
                'followRedirects' => true,
                'maxRedirects' => 2,
                'allowCrossOriginRedirects' => true,
            ]),
        ))->toThrow(OutboundHttpException::class, 'Cross-origin 307/308 redirect is not allowed.');

        expect($server->capture('steal', 0.0))->toBeNull()
            ->and($server->capture('redirect-cross')['body'] ?? null)->toBe('super-secret-body');
    } finally {
        $server->stop();
    }
})->group('real-transport');

it('drops body and Authorization on an opted-in cross-origin 302 on the real wire', function (): void {
    $server = new RealTransportServer;

    try {
        $server->client->send(
            'POST',
            $server->url('app.test', '/redirect-cross-302'),
            function (OutboundRequest $request): void {
                $request->withBody('super-secret-body', 'text/plain')
                    ->withHeader('Authorization', 'Bearer real-leak');
            },
            realTransportOptions([
                'followRedirects' => true,
                'maxRedirects' => 2,
                'allowCrossOriginRedirects' => true,
            ]),
        );

        $first = $server->capture('redirect-cross-302');
        $second = $server->capture('steal');

        expect($first)->not->toBeNull()
            ->and($first['authorization'])->toContain('Bearer real-leak')
            ->and($first['body'])->toBe('super-secret-body')
            ->and($second)->not->toBeNull()
            ->and($second['authorization'])->toBe('')
            ->and($second['body'])->toBe('')
            ->and($second['method'])->toBe('GET');
    } finally {
        $server->stop();
    }
})->group('real-transport');

it('drops body after same-origin 303 on the real wire', function (): void {
    $server = new RealTransportServer;

    try {
        $server->client->send(
            'POST',
            $server->url('app.test', '/redirect-303'),
            function (OutboundRequest $request): void {
                $request->withBody('should-not-follow', 'text/plain');
            },
            realTransportOptions([
                'followRedirects' => true,
                'maxRedirects' => 2,
            ]),
        );

        $first = $server->capture('redirect-303');
        $second = $server->capture('after-303');

        expect($first['body'] ?? null)->toBe('should-not-follow')
            ->and($second)->not->toBeNull()
            ->and($second['method'])->toBe('GET')
            ->and($second['body'])->toBe('');
    } finally {
        $server->stop();
    }
})->group('real-transport');

it('rejects oversize Content-Length on the real wire without keeping the body', function (): void {
    $server = new RealTransportServer;

    try {
        expect(fn () => $server->client->send(
            'GET',
            $server->url('app.test', '/big?n=500000'),
            static function (OutboundRequest $request): void {},
            realTransportOptions(['maxResponseBytes' => 1024]),
        ))->toThrow(OutboundHttpException::class);
    } finally {
        $server->stop();
    }
})->group('real-transport');

it('still pins when the shared Http factory has a global handler (Guzzle bypass attempt)', function (): void {
    $server = new RealTransportServer;

    // Poison the SHARED factory — PinnedHttpClient must use a private one.
    $unchecked = HandlerStack::create(function (
        RequestInterface $request,
        array $options,
    ) use ($server) {
        // Deliberately connect without CONNECT_TO to a different capture path
        // if this handler were used. Hitting /unchecked proves the bypass.
        $url = 'http://127.0.0.1:'.$server->port.'/unchecked';
        $raw = file_get_contents($url);

        return Create::promiseFor(
            new Response(200, [], $raw === false ? 'unchecked-fail' : $raw)
        );
    });
    Http::globalOptions(['handler' => $unchecked]);

    try {
        $response = $server->client->send(
            'GET',
            $server->url('app.test', '/ok'),
            static function (OutboundRequest $request): void {},
            realTransportOptions(),
        );

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('pinned-ok:app.test:'.$server->port)
            ->and($server->capture('ok'))->not->toBeNull()
            ->and($server->capture('unchecked', 0.0))->toBeNull();
    } finally {
        Http::globalOptions([]);
        $server->stop();
    }
})->group('real-transport');

it('applies withQuery to the first hop only and strips secret headers on cross-origin 302', function (): void {
    $server = new RealTransportServer;

    try {
        $response = $server->client->send(
            'POST',
            $server->url('app.test', '/redirect-cross-302'),
            function (OutboundRequest $request): void {
                $request->withBody('secret-body', 'text/plain')
                    ->withQuery(['token' => 'QSECRET'])
                    ->withHeader('X-Api-Key', 'secret-key')
                    ->withHeader('Authorization', 'Bearer real-leak')
                    ->accept('application/json');
            },
            realTransportOptions([
                'followRedirects' => true,
                'maxRedirects' => 2,
                'allowCrossOriginRedirects' => true,
            ]),
        );

        expect($response->status())->toBe(200);

        $first = $server->capture('redirect-cross-302');
        $second = $server->capture('steal');

        expect($first)->not->toBeNull()
            ->and($first['query'] ?? '')->toContain('token=QSECRET')
            ->and($first['x_api_key'] ?? '')->toBe('secret-key')
            ->and($second)->not->toBeNull()
            ->and($second['query'] ?? '')->not->toContain('QSECRET')
            ->and($second['x_api_key'] ?? '')->toBe('')
            ->and($second['authorization'] ?? '')->toBe('')
            ->and($second['accept'] ?? '')->toContain('application/json');
    } finally {
        $server->stop();
    }
})->group('real-transport');

it('still pins when constructed with a poisoned factory global handler', function (): void {
    $portHolder = new class
    {
        public int $port = 0;
    };

    $poisoned = new HttpFactory;
    $unchecked = HandlerStack::create(function (
        RequestInterface $request,
        array $options,
    ) use ($portHolder) {
        $url = 'http://127.0.0.1:'.$portHolder->port.'/unchecked';
        $raw = @file_get_contents($url);

        return Create::promiseFor(
            new Response(200, [], $raw === false ? 'unchecked-fail' : $raw)
        );
    });
    $poisoned->globalOptions(['handler' => $unchecked]);

    $server = new RealTransportServer(factory: $poisoned);
    $portHolder->port = $server->port;

    try {
        $response = $server->client->send(
            'GET',
            $server->url('app.test', '/ok'),
            static function (OutboundRequest $request): void {},
            realTransportOptions(),
        );

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('pinned-ok:app.test:'.$server->port)
            ->and($server->capture('ok'))->not->toBeNull()
            ->and($server->capture('unchecked', 0.0))->toBeNull();
    } finally {
        $server->stop();
    }
})->group('real-transport');

it('aborts an oversized stream without Content-Length via progress and bounds memory', function (): void {
    $server = new RealTransportServer;
    $limit = 256 * 1024;

    try {
        $before = memory_get_usage(true);

        expect(fn () => $server->client->send(
            'GET',
            $server->url('app.test', '/stream-nolength'),
            static function (OutboundRequest $request): void {},
            realTransportOptions(['maxResponseBytes' => $limit]),
        ))->toThrow(OutboundHttpException::class, 'Response exceeds max_response_bytes.');

        $delta = memory_get_usage(true) - $before;
        // ~32 MiB stream must not be fully buffered (limit is 256 KiB).
        expect($delta)->toBeLessThan(8 * 1024 * 1024);
    } finally {
        $server->stop();
    }
})->group('real-transport');

it('names a real timeout, TLS failure and refused connection without the address', function (): void {
    $server = new RealTransportServer;
    $failureOf = static function (string $url, int $timeoutSeconds = 3) use ($server): OutboundHttpException {
        try {
            $server->client->send('GET', $url, static function (OutboundRequest $request): void {}, realTransportOptions([
                'timeoutSeconds' => $timeoutSeconds,
                'connectTimeoutSeconds' => 1,
            ]));
        } catch (OutboundHttpException $exception) {
            return $exception;
        }

        throw new RuntimeException('The request did not fail.');
    };

    try {
        // A TLS handshake against the plain-HTTP listener. First: php -S is
        // single-threaded, and /slow keeps it busy afterwards.
        $tls = $failureOf('https://app.test:'.$server->port.'/ok');
        $timeout = $failureOf($server->url('app.test', '/slow'), timeoutSeconds: 1);
        // A loopback port with no listener: bound, read, closed again.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);
        $refused = $failureOf("http://app.test:{$closedPort}/ok");
    } finally {
        $server->stop();
    }

    expect($timeout->transportErrorKind())->toBe(OutboundTransportErrorKindEnum::Timeout)
        ->and($tls->transportErrorKind())->toBe(OutboundTransportErrorKindEnum::Tls)
        ->and($refused->transportErrorKind())->toBe(OutboundTransportErrorKindEnum::Connection);

    foreach ([$timeout, $tls, $refused] as $exception) {
        expect($exception->getMessage())->not->toContain('127.0.0.1')->not->toContain('app.test')
            ->and($exception->getPrevious()?->getMessage())->toBe('Underlying transport error.');
    }
})->group('real-transport');

/**
 * The test forward proxy (tests/Support/real-transport-proxy.php) on a free
 * loopback port, with the request lines it saw.
 */
final class RealTransportProxy
{
    public int $port;

    public string $logFile;

    /** @var resource */
    private $process;

    public function __construct(?string $credentials = null)
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'outbound-proxy-');
        // exec: the proxy itself is the process stopped later, not a shell around it.
        $command = sprintf(
            'exec %s %s %d %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2).'/Support/real-transport-proxy.php'),
            $this->port,
            escapeshellarg($this->logFile),
            $credentials === null ? '' : escapeshellarg($credentials),
        );
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start the test proxy.');
        }

        $this->process = $process;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline && @fsockopen('127.0.0.1', $this->port, $errorNumber, $errorMessage, 0.2) === false) {
            usleep(50_000);
        }
    }

    /**
     * @return list<string>
     */
    public function requestLines(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile))));
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->logFile);
    }
}

function clientThroughProxy(RealTransportServer $server, RealTransportProxy $proxy, ?string $credentials = null): PinnedHttpClient
{
    return new PinnedHttpClient(
        new HttpFactory,
        new RemoteUrlGuard((new FakeHostResolver)->with('app.test', ['127.0.0.1'])->with('metadata.test', ['169.254.169.254']), allowPrivateHosts: true),
        new OutboundHttpLimits,
        null,
        null,
        EgressProxy::fromUrl('http://'.($credentials === null ? '' : $credentials.'@').'127.0.0.1:'.$proxy->port),
    );
}

it('tunnels through the egress proxy to the pinned address, never naming the host', function (): void {
    $server = new RealTransportServer;
    $proxy = new RealTransportProxy;

    try {
        $response = clientThroughProxy($server, $proxy)->send('GET', $server->url('app.test', '/ok'), static function (OutboundRequest $request): void {}, realTransportOptions());

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('pinned-ok:app.test:'.$server->port)
            ->and($proxy->requestLines())->toBe(["CONNECT 127.0.0.1:{$server->port} HTTP/1.1"]);
    } finally {
        $proxy->stop();
        $server->stop();
    }
})->group('real-transport');

it('authenticates to the egress proxy, and keeps its credentials out of a refusal', function (): void {
    $server = new RealTransportServer;
    $proxy = new RealTransportProxy('outbound:s3cret');

    try {
        $response = clientThroughProxy($server, $proxy, 'outbound:s3cret')->send('GET', $server->url('app.test', '/ok'), static function (OutboundRequest $request): void {}, realTransportOptions());

        try {
            clientThroughProxy($server, $proxy, 'outbound:wrong-s3cret')->send('GET', $server->url('app.test', '/ok'), static function (OutboundRequest $request): void {}, realTransportOptions());
            $chain = 'not refused';
        } catch (OutboundHttpException $exception) {
            $chain = '';
            for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
                $chain .= $link->getMessage()."\n";
            }
        }

        expect($response->status())->toBe(200)
            ->and($chain)->toContain('Outbound request failed:')
            ->and($chain)->not->toContain('s3cret')
            ->and($chain)->not->toContain('outbound:');
    } finally {
        $proxy->stop();
        $server->stop();
    }
})->group('real-transport');

it('never reaches the egress proxy for a destination the guard refuses', function (): void {
    $server = new RealTransportServer;
    $proxy = new RealTransportProxy;

    try {
        expect(fn () => clientThroughProxy($server, $proxy)->send('GET', 'http://metadata.test/latest/meta-data/', static function (OutboundRequest $request): void {}, realTransportOptions()))
            ->toThrow(UnsafeUrl::class)
            ->and($proxy->requestLines())->toBe([]);
    } finally {
        $proxy->stop();
        $server->stop();
    }
})->group('real-transport');
