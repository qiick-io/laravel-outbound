<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
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

    public function __construct()
    {
        $this->captureDir = sys_get_temp_dir().'/outbound-rt-'.bin2hex(random_bytes(8));
        mkdir($this->captureDir, 0700, true);

        $router = dirname(__DIR__, 2).'/Support/real-transport-server.php';
        $this->port = 18080 + random_int(0, 2000);

        $cmd = sprintf(
            'REAL_TRANSPORT_CAPTURE_DIR=%s %s -S %s:%d %s',
            escapeshellarg($this->captureDir),
            escapeshellarg(PHP_BINARY),
            $this->pinAddress,
            $this->port,
            escapeshellarg($router),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->captureDir.'/server.out', 'w'],
            2 => ['file', $this->captureDir.'/server.err', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start php -S for real-transport tests.');
        }

        $this->process = $process;

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($this->pinAddress, $this->port, $errno, $errstr, 0.2);

            if (is_resource($fp)) {
                fclose($fp);
                break;
            }

            usleep(50_000);
        }

        $resolver = (new FakeHostResolver)
            ->with('app.test', [$this->pinAddress])
            ->with('evil.test', [$this->pinAddress]);

        $this->client = new PinnedHttpClient(
            app(HttpFactory::class),
            new RemoteUrlGuard($resolver, allowPrivateHosts: true),
        );
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

    public function capture(string $name): ?array
    {
        $path = $this->captureDir.'/'.$name.'.json';

        if (! is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function url(string $host, string $path): string
    {
        return 'http://'.$host.':'.$this->port.$path;
    }
}

function realTransportSender(): callable
{
    return function (PendingRequest $request, string $url, string $method) {
        return match (strtoupper($method)) {
            'POST' => $request->post($url),
            default => $request->get($url),
        };
    };
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
        totalDeadlineSeconds: $overrides['totalDeadlineSeconds'] ?? 10,
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
            realTransportSender(),
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

it('does not forward Authorization across a cross-origin 307 on the real wire', function (): void {
    $server = new RealTransportServer;

    try {
        $server->client->send(
            'POST',
            $server->url('app.test', '/redirect-cross'),
            function (PendingRequest $request, string $url, string $method) {
                return $request->withBody('super-secret-body', 'text/plain')->send($method, $url);
            },
            realTransportOptions([
                'followRedirects' => true,
                'maxRedirects' => 2,
                'allowCrossOriginRedirects' => true,
                'headers' => [
                    'Authorization' => 'Bearer real-leak',
                    'Cookie' => 'session=1',
                ],
            ]),
        );

        $first = $server->capture('redirect-cross');
        $second = $server->capture('steal');

        expect($first)->not->toBeNull()
            ->and($first['authorization'])->toContain('Bearer real-leak')
            ->and($first['body'])->toBe('super-secret-body')
            ->and($first['host'])->toBe('app.test:'.$server->port)
            ->and($second)->not->toBeNull()
            ->and($second['authorization'])->toBe('')
            ->and($second['cookie'])->toBe('')
            ->and($second['host'])->toBe('evil.test:'.$server->port);
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
            realTransportSender(),
            realTransportOptions(['maxResponseBytes' => 1024]),
        ))->toThrow(OutboundHttpException::class);
    } finally {
        $server->stop();
    }
})->group('real-transport');
