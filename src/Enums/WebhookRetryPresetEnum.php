<?php

declare(strict_types=1);

namespace Qiick\Outbound\Enums;

use Qiick\Outbound\Retry\RetrySchedule;

/**
 * Named retry schedules (`outbound.webhooks.retry_preset`,
 * `webhook_endpoints.retry_preset`). `Standard` is the Standard Webhooks
 * example: 10 attempts over 75 h 35 min. `Short` is for a migration window
 * only. Backing values are stored, so they never change.
 *
 * @api
 */
enum WebhookRetryPresetEnum: string
{
    case Standard = 'standard';
    case Short = 'short';

    /**
     * The wait before each retry, from the previous attempt.
     *
     * @return list<int>
     */
    public function delays(): array
    {
        return match ($this) {
            self::Standard => RetrySchedule::standardDelays(),
            self::Short => RetrySchedule::shortDelays(),
        };
    }
}
