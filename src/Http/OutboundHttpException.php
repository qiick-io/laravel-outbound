<?php

declare(strict_types=1);

namespace Qiick\Outbound\Http;

use RuntimeException;

/**
 * Fail-closed transport / redirect / response-size error from PinnedHttpClient.
 * Message must not include response bodies or secrets.
 *
 * @api
 */
final class OutboundHttpException extends RuntimeException {}
