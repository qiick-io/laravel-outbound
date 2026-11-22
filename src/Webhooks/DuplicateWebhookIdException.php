<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * The webhook-id was already claimed for this dedupe scope (replay / duplicate delivery).
 *
 * Message stays {@see WebhookException} wording for BC; catch this class instead of
 * string-matching.
 *
 * @api
 */
final class DuplicateWebhookIdException extends WebhookException {}
