<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

/**
 * The seen-id store could not record a claim (fail closed).
 *
 * Message stays {@see WebhookException} wording for BC; catch this class instead of
 * string-matching.
 *
 * @api
 */
final class WebhookIdStoreFailedException extends WebhookException {}
