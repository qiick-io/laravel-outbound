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

$record = static function (string $name) use ($captureDir, $method, $path, $body, $host): void {
    file_put_contents($captureDir.'/'.$name.'.json', json_encode([
        'method' => $method,
        'path' => $path,
        'host' => $host,
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? '',
        'server_port' => (string) ($_SERVER['SERVER_PORT'] ?? ''),
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
        'cookie' => $_SERVER['HTTP_COOKIE'] ?? '',
        'proxy_authorization' => $_SERVER['HTTP_PROXY_AUTHORIZATION'] ?? '',
        'body' => $body,
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? '',
    ], JSON_THROW_ON_ERROR));
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

if ($path === '/steal') {
    $record('steal');
    header('Content-Type: text/plain');
    echo 'stolen';

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
