<?php

declare(strict_types=1);

namespace Qiick\Outbound\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;

/**
 * Tells an endpoint's owner by email that its webhooks stopped.
 *
 * Standard Webhooks "Deliverability and reliability": when deliveries keep
 * failing, notify the consumer through another channel and disable future
 * delivery (standards review R8, Luca's Q2). The mail names the endpoint and
 * its host only, never its URL: a path or a query can carry a token. Queued,
 * so a worker run never waits on the mailer.
 *
 * @api
 */
final class WebhookEndpointDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $endpointPublicId,
        public readonly string $endpointName,
        public readonly string $endpointHost,
        public readonly WebhookEndpointDisabledReasonEnum $reason,
        public readonly CarbonImmutable $disabledAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Webhook endpoint \"{$this->endpointName}\" disabled")
            ->line("Webhook deliveries to {$this->endpointName} ({$this->endpointHost}) stopped at {$this->disabledAt->utc()->toDateTimeString()} UTC.")
            ->line(match ($this->reason) {
                WebhookEndpointDisabledReasonEnum::GoneHttp410 => 'It answered 410 Gone, which asks the sender to stop.',
                WebhookEndpointDisabledReasonEnum::AutoFailure => 'It has not accepted a webhook for days, and every recent attempt failed.',
                WebhookEndpointDisabledReasonEnum::Manual => 'A user disabled it.',
            })
            ->line('Fix the receiving end, then enable the endpoint again: its pending deliveries resume.');
    }
}
