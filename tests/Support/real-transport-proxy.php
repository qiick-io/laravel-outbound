<?php

declare(strict_types=1);

/**
 * A minimal forward proxy for the real-transport tests of the egress proxy.
 *
 * Usage: php real-transport-proxy.php <port> <log file> [<user:password>]
 *
 * Every request line is appended to the log file ("CONNECT host:port" or the
 * refused line). With credentials, a request without the matching
 * Proxy-Authorization gets 407. A CONNECT is tunnelled to its target; any
 * other method gets 405, so a test sees that the client only ever tunnels.
 * One connection at a time is enough for the tests.
 */
[$script, $port, $logFile] = $argv;
$expectedCredentials = $argv[3] ?? null;

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errorNumber, $errorMessage);

if ($server === false) {
    fwrite(STDERR, "Cannot listen: {$errorMessage}\n");

    exit(1);
}

while (true) {
    $client = @stream_socket_accept($server, -1);

    if ($client === false) {
        continue;
    }

    $head = '';

    while (! str_contains($head, "\r\n\r\n") && ! feof($client)) {
        $head .= (string) fread($client, 1);
    }

    $lines = explode("\r\n", $head);
    $requestLine = $lines[0];
    file_put_contents($logFile, $requestLine."\n", FILE_APPEND | LOCK_EX);

    if ($expectedCredentials !== null && ! in_array('Proxy-Authorization: Basic '.base64_encode($expectedCredentials), $lines, true)) {
        fwrite($client, "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm=\"test\"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);

        continue;
    }

    if (preg_match('/^CONNECT (\S+):(\d+) HTTP\/1\.[01]$/', $requestLine, $target) !== 1) {
        fwrite($client, "HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);

        continue;
    }

    $upstream = @stream_socket_client("tcp://{$target[1]}:{$target[2]}", $errorNumber, $errorMessage, 2);

    if ($upstream === false) {
        fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);

        continue;
    }

    fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");

    // Pump bytes both ways until either side closes.
    while (true) {
        $readable = [$client, $upstream];
        $writable = $except = null;

        if (stream_select($readable, $writable, $except, 5) < 1) {
            break;
        }

        foreach ($readable as $socket) {
            $chunk = fread($socket, 8192);

            if ($chunk === '' || $chunk === false) {
                break 2;
            }

            fwrite($socket === $client ? $upstream : $client, $chunk);
        }
    }

    fclose($upstream);
    fclose($client);
}
