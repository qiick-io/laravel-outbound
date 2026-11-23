<?php

declare(strict_types=1);

namespace Qiick\Outbound\Retry;

use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;

/**
 * What {@see RetryPolicy} decided after a failed attempt: retry after
 * `delaySeconds`, or stop automatic retries for `exhaustedReason`.
 *
 * @api
 */
final readonly class RetryDecision
{
    private function __construct(
        public ?int $delaySeconds,
        public ?WebhookExhaustedReasonEnum $exhaustedReason,
    ) {}

    public static function retryIn(int $delaySeconds): self
    {
        return new self($delaySeconds, null);
    }

    public static function exhausted(WebhookExhaustedReasonEnum $exhaustedReason): self
    {
        return new self(null, $exhaustedReason);
    }

    public function isExhausted(): bool
    {
        return $this->exhaustedReason !== null;
    }
}
