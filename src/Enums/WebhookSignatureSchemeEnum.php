<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

/**
 * Standard Webhooks signature scheme of a secret (`webhook_endpoint_secrets.scheme`).
 *
 * Only `V1` (symmetric HMAC-SHA256) signs in 1.0. `V2a` (asymmetric) is
 * reserved in the schema so it can ship after 1.0 without a migration
 * (standards review M15, L1). Backing values are stored, so they never change.
 *
 * @api
 */
enum WebhookSignatureSchemeEnum: string
{
    case V1 = 'v1';
    case V2a = 'v2a';
}
