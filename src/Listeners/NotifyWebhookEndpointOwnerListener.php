<?php

declare(strict_types=1);

namespace Qiick\Outbound\Listeners;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Qiick\Outbound\Contracts\WebhookEndpointNoticeRecipientsContract;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Notifications\WebhookEndpointDisabledNotification;

/**
 * Emails the endpoint's notice recipients when the package disabled it:
 * the user who created it, or whoever the product's
 * {@see WebhookEndpointNoticeRecipientsContract} names.
 *
 * Only automatic disables (410 Gone, the failure streak): a manual disable
 * was someone's own decision. Nothing is sent without recipients. Turned off with
 * `outbound.webhooks.auto_disable.notify_owner`; a product can still listen
 * to {@see WebhookEndpointDisabledEvent} for its own channels.
 *
 * @api
 */
final class NotifyWebhookEndpointOwnerListener
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly WebhookEndpointNoticeRecipientsContract $recipients,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function handle(WebhookEndpointDisabledEvent $event): void
    {
        if ($event->reason === WebhookEndpointDisabledReasonEnum::Manual || $this->config->get('outbound.webhooks.auto_disable.notify_owner', true) === false) {
            return;
        }

        $endpoint = WebhookEndpoint::withTrashed()->where('public_id', $event->endpointPublicId)->first();

        if ($endpoint === null) {
            return;
        }

        $endpointPageUrl = $this->config->get('outbound.webhooks.auto_disable.endpoint_url');

        // An empty list sends nothing.
        $this->notifications->send([...$this->recipients->recipientsFor($endpoint)], new WebhookEndpointDisabledNotification(
            $endpoint->public_id,
            $endpoint->displayHost(),
            $event->reason,
            $event->disabledAt,
            is_string($endpointPageUrl) && $endpointPageUrl !== '' ? str_replace('{endpoint}', rawurlencode($endpoint->public_id), $endpointPageUrl) : null,
        ));
    }
}
