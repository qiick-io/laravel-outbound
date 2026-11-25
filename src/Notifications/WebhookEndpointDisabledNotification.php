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
 * delivery (standards review R8, the maintainer's decision Q2). The mail carries no text a
 * tenant wrote: the endpoint's free-form name could hold a bare URL that mail
 * clients turn into a link. It names the endpoint by its host and public id,
 * and its only link is the product's own endpoint page, from config. Queued,
 * so a worker run never waits on the mailer.
 *
 * @api
 */
final class WebhookEndpointDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $endpointHost  a valid host name or IP literal, lowercased
     * @param  string|null  $endpointPageUrl  the product's page for the endpoint
     */
    public function __construct(
        public readonly string $endpointPublicId,
        public readonly string $endpointHost,
        public readonly WebhookEndpointDisabledReasonEnum $reason,
        public readonly CarbonImmutable $disabledAt,
        public readonly ?string $endpointPageUrl,
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
        $mail = (new MailMessage)
            ->subject("Webhook endpoint disabled: {$this->endpointHost}")
            ->line("Webhook deliveries to {$this->endpointHost} (endpoint {$this->endpointPublicId}) stopped at {$this->disabledAt->utc()->toDateTimeString()} UTC.")
            ->line(match ($this->reason) {
                WebhookEndpointDisabledReasonEnum::GoneHttp410 => 'It answered 410 Gone, which asks the sender to stop.',
                WebhookEndpointDisabledReasonEnum::AutoFailure => 'It has not accepted a webhook for days, and every recent attempt failed.',
                WebhookEndpointDisabledReasonEnum::Manual => 'A user disabled it.',
            });

        if ($this->endpointPageUrl !== null) {
            $mail->action('Open the endpoint', $this->endpointPageUrl);
        }

        return $mail->line('Fix the receiving end, then enable the endpoint again: its pending deliveries resume.');
    }
}
