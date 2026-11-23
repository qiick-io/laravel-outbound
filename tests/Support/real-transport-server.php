<?php

declare(strict_types=1);

/**
 * Router script for real-transport Pest tests (php -S).
 * Writes hop captures under the directory in REAL_TRANSPORT_CAPTURE_DIR.
 */
$captureDir = getenv('REAL_TRANSPORT_CAPTURE_DIR') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = file_get_contents('php://input') ?: '';
$host = $_SERVER['HTTP_HOST'] ?? '';

$query = $_SERVER['QUERY_STRING'] ?? '';
$record = static function (string $name) use ($captureDir, $method, $path, $body, $host, $query): void {
    // Atomic publish BEFORE any response bytes leave the process: write a temp
    // file then rename so readers never see a partial JSON capture.
    $payload = json_encode([
        'method' => $method,
        'path' => $path,
        'query' => $query,
        'host' => $host,
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? '',
        'server_port' => (string) ($_SERVER['SERVER_PORT'] ?? ''),
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
        'cookie' => $_SERVER['HTTP_COOKIE'] ?? '',
        'proxy_authorization' => $_SERVER['HTTP_PROXY_AUTHORIZATION'] ?? '',
        'x_api_key' => $_SERVER['HTTP_X_API_KEY'] ?? '',
        'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'body' => $body,
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? '',
    ], JSON_THROW_ON_ERROR);

    $target = $captureDir.'/'.$name.'.json';
    $tmp = $captureDir.'/'.$name.'.'.bin2hex(random_bytes(4)).'.tmp';

    if (file_put_contents($tmp, $payload) === false) {
        throw new RuntimeException('Failed to write real-transport capture temp file.');
    }

    if (! rename($tmp, $target)) {
        @unlink($tmp);

        throw new RuntimeException('Failed to publish real-transport capture file.');
    }
};

if ($path === '/ok') {
    $record('ok');
    header('Content-Type: text/plain');
    // Host is the virtual name; PHP built-in server leaves SERVER_ADDR empty —
    // the pin is proven because app.test is not in real DNS and only CONNECT_TO
    // to 127.0.0.1 can reach this listener.
    echo 'pinned-ok:'.$host;

    return true;
}

if ($path === '/slow') {
    // Longer than the 1 s client timeout of the transport-error test.
    sleep(3);
    echo 'too late';

    return true;
}

if ($path === '/big') {
    $record('big');
    $size = (int) ($_GET['n'] ?? 2_000_000);
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.$size);
    echo str_repeat('x', $size);

    return true;
}

if ($path === '/redirect-cross') {
    $record('redirect-cross');
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    header('Location: http://evil.test:'.$port.'/steal', true, 307);

    return true;
}

if ($path === '/redirect-cross-302') {
    $record('redirect-cross-302');
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    header('Location: http://evil.test:'.$port.'/steal', true, 302);

    return true;
}

if ($path === '/redirect-303') {
    $record('redirect-303');
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    header('Location: http://app.test:'.$port.'/after-303', true, 303);

    return true;
}

if ($path === '/after-303') {
    $record('after-303');
    header('Content-Type: text/plain');
    echo 'after-303';

    return true;
}

if ($path === '/steal') {
    $record('steal');
    header('Content-Type: text/plain');
    echo 'stolen';

    return true;
}

if ($path === '/unchecked') {
    $record('unchecked');
    header('Content-Type: text/plain');
    echo 'unchecked-ok';

    return true;
}

if ($path === '/stream-nolength') {
    $record('stream-nolength');
    // No Content-Length — progress callback must abort oversized downloads.
    header('Content-Type: application/octet-stream');
    header('Transfer-Encoding: chunked');
    $chunk = str_repeat('x', 64 * 1024);
    for ($i = 0; $i < 500; $i++) {
        echo dechex(strlen($chunk)), "\r\n", $chunk, "\r\n";
        flush();
    }
    echo "0\r\n\r\n";

    return true;
}

if ($path === '/redirect-down') {
    $record('redirect-down');
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    header('Location: http://app.test:'.$port.'/ok', true, 302);

    return true;
}

http_response_code(404);
echo 'not-found';

return true;
