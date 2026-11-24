<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

/**
 * Why automatic retries of a delivery stopped
 * (`webhook_deliveries.exhausted_reason`): every attempt was used, the
 * receiver asked to wait longer than `retry_after_cap_seconds` (standards
 * review R4: the wait is never shortened to the cap), or it answered 410 Gone
 * (Standard Webhooks "Delivery success and failure"), or its body was pruned
 * at the end of the retention period while it was still pending. A manual
 * redelivery is still possible, except once the body is pruned. Backing
 * values are stored, so they never change.
 *
 * @api
 */
enum WebhookExhaustedReasonEnum: string
{
    case MaxAttempts = 'max_attempts';
    case RetryAfterExceedsCap = 'retry_after_exceeds_cap';
    case Gone = 'gone';
    case PayloadExpired = 'payload_expired';
}
