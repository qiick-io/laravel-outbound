<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use RuntimeException;

/**
 * Fail-closed Standard Webhooks signing or verification error.
 * Messages must never include secrets, raw bodies, or signature material.
 *
 * Prefer the dedicated subclasses for duplicate id, store failure, and
 * signature miss when branching in product code; messages stay stable for BC.
 *
 * @api
 */
class WebhookException extends RuntimeException {}
