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
        $endpointName = self::plainText($this->endpointName);

        return (new MailMessage)
            ->subject("Webhook endpoint \"{$endpointName}\" disabled")
            ->line(self::markdownEscaped("Webhook deliveries to {$endpointName} ({$this->endpointHost})")." stopped at {$this->disabledAt->utc()->toDateTimeString()} UTC.")
            ->line(match ($this->reason) {
                WebhookEndpointDisabledReasonEnum::GoneHttp410 => 'It answered 410 Gone, which asks the sender to stop.',
                WebhookEndpointDisabledReasonEnum::AutoFailure => 'It has not accepted a webhook for days, and every recent attempt failed.',
                WebhookEndpointDisabledReasonEnum::Manual => 'A user disabled it.',
            })
            ->line('Fix the receiving end, then enable the endpoint again: its pending deliveries resume.');
    }

    /**
     * The name is the tenant's text: one line of at most 80 characters, with
     * no control, format or line-break characters, so it cannot add lines to
     * the mail or run on.
     */
    private static function plainText(string $text): string
    {
        // @phpstan-ignore argument.type (a fixed pattern on scrubbed UTF-8: preg_replace cannot fail)
        $singleLine = trim(preg_replace('/[\s\p{Cc}\p{Cf}\p{Z}]+/u', ' ', mb_scrub($text, 'UTF-8')));

        return mb_strlen($singleLine) > 80 ? mb_substr($singleLine, 0, 79).'…' : $singleLine;
    }

    /**
     * Mail lines are Markdown: every ASCII punctuation mark is escaped, so a
     * name can make no link, emphasis or HTML.
     */
    private static function markdownEscaped(string $text): string
    {
        // @phpstan-ignore return.type (a fixed pattern on valid UTF-8: preg_replace cannot fail)
        return preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $text);
    }
}
