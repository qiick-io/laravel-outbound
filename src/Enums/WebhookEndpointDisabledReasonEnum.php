<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

/**
 * Why an endpoint stopped receiving deliveries (`webhook_endpoints.disabled_reason`).
 *
 * `GoneHttp410` is set at once when the destination answers 410 Gone;
 * `AutoFailure` after the configured failure streak; `Manual` by a user.
 * Backing values are stored, so they never change.
 *
 * @api
 */
enum WebhookEndpointDisabledReasonEnum: string
{
    case Manual = 'manual';
    case AutoFailure = 'auto_failure';
    case GoneHttp410 = 'gone_410';
}
