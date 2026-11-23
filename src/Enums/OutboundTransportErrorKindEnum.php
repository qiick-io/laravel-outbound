<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

/**
 * Why a request through `PinnedHttpClient` failed below HTTP: the connection
 * or the whole chain timed out, the TLS handshake or certificate failed,
 * a name did not resolve inside the transport, or the connection was refused,
 * reset or closed. A webhook attempt stores the value as its `error_code`.
 * Backing values are stored, so they never change.
 *
 * @api
 */
enum OutboundTransportErrorKindEnum: string
{
    case Timeout = 'timeout';
    case Tls = 'tls';
    case Dns = 'dns';
    case Connection = 'connection';
}
