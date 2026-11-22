<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use RuntimeException;

/**
 * Fail-closed Standard Webhooks signing or verification error.
 * Messages must never include secrets, raw bodies, or signature material.
 *
 * @api
 */
final class WebhookException extends RuntimeException {}
