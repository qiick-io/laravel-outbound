<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * No candidate signature matched any configured secret (`hash_equals` miss).
 *
 * Message stays {@see WebhookException} wording for BC; catch this class instead of
 * string-matching.
 *
 * @api
 */
final class InvalidWebhookSignatureException extends WebhookException {}
