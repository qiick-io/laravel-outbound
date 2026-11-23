<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use Qiick\Outbound\Enums\OutboundTransportErrorKindEnum;
use RuntimeException;
use Throwable;

/**
 * Fail-closed transport / redirect / response-size error from PinnedHttpClient.
 * Message must not include response bodies or secrets.
 *
 * A transport failure also says which kind it was ({@see transportErrorKind()}),
 * so a caller can record it without parsing messages; refusals the client
 * makes itself (size, redirect rules) have none.
 *
 * @api
 */
final class OutboundHttpException extends RuntimeException
{
    private ?OutboundTransportErrorKindEnum $transportErrorKind = null;

    public static function forTransport(OutboundTransportErrorKindEnum $transportErrorKind, string $message, ?Throwable $previous = null): self
    {
        $exception = new self($message, previous: $previous);
        $exception->transportErrorKind = $transportErrorKind;

        return $exception;
    }

    public function transportErrorKind(): ?OutboundTransportErrorKindEnum
    {
        return $this->transportErrorKind;
    }
}
