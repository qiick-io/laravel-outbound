<?php

declare(strict_types=1);

namespace Qiick\Outbound\Delivery;

use Carbon\CarbonImmutable;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Decides, after a failed attempt, whether an endpoint is disabled.
 *
 * Standards review R8 (Luca's Q2 of 2026-11-22), on by default in every
 * product: 410 Gone disables at once (Standard Webhooks "Delivery success
 * and failure": the sender should disable the endpoint); otherwise an
 * endpoint is disabled once it has had no successful attempt for
 * `no_success_days` (its streak started at least that long ago) and at least
 * `min_consecutive_failures` failed attempts in a row. Five days is longer
 * than one full 75 h schedule, so at least one delivery has used every retry
 * first. The endpoint's `auto_disable` overrides the configured switch.
 *
 * @api
 */
final class EndpointDisablePolicy
{
    public function __construct(
        private readonly bool $isEnabled,
        private readonly int $noSuccessDays,
        private readonly int $minConsecutiveFailures,
    ) {}

    /**
     * @param  WebhookEndpoint  $endpoint  with its failure streak after the attempt
     * @param  int|null  $httpStatus  the attempt's status; null without a response
     */
    public function reasonToDisable(WebhookEndpoint $endpoint, ?int $httpStatus, CarbonImmutable $now): ?WebhookEndpointDisabledReasonEnum
    {
        if (! ($endpoint->auto_disable ?? $this->isEnabled)) {
            return null;
        }

        if ($httpStatus === 410) {
            return WebhookEndpointDisabledReasonEnum::GoneHttp410;
        }

        $failingSince = $endpoint->failing_since;

        if ($failingSince === null || $endpoint->consecutive_failures < $this->minConsecutiveFailures) {
            return null;
        }

        return $failingSince <= $now->subDays($this->noSuccessDays) ? WebhookEndpointDisabledReasonEnum::AutoFailure : null;
    }
}
