<?php

declare(strict_types=1);

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\OutboundHttpLimits;
use Qiick\Outbound\Http\OutboundRequest;
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

    public function __construct(?OutboundHttpLimits $limits = null)
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
            new HttpFactory,
            new RemoteUrlGuard($resolver, allowPrivateHosts: true),
            $limits ?? new OutboundHttpLimits,
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

        expect($server->capture('steal'))->toBeNull()
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
            ->and($server->capture('unchecked'))->toBeNull();
    } finally {
        Http::globalOptions([]);
        $server->stop();
    }
})->group('real-transport');

it('applies withQuery to the first hop only and strips secret headers on cross-origin 302', function (): void {
    $server = new RealTransportServer;

    try {
        $server->client->send(
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
