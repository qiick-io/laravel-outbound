<?php

declare(strict_types=1);

namespace Qiick\Outbound\Listeners;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Notifications\WebhookEndpointDisabledNotification;

/**
 * Emails the user who created an endpoint when the package disabled it.
 *
 * Only automatic disables (410 Gone, the failure streak): a manual disable
 * was someone's own decision. The owner is `created_by`, loaded with the
 * application's user model (`auth.providers.users.model`); nothing is sent
 * when there is no owner or the model cannot be notified. Turned off with
 * `outbound.webhooks.auto_disable.notify_owner`; a product can still listen
 * to {@see WebhookEndpointDisabledEvent} for its own channels.
 *
 * @api
 */
final class NotifyWebhookEndpointOwnerListener
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function handle(WebhookEndpointDisabledEvent $event): void
    {
        if ($event->reason === WebhookEndpointDisabledReasonEnum::Manual || $this->config->get('outbound.webhooks.auto_disable.notify_owner', true) === false) {
            return;
        }

        $endpoint = WebhookEndpoint::withTrashed()->where('public_id', $event->endpointPublicId)->first();
        $ownerModel = $this->config->get('auth.providers.users.model');

        if ($endpoint?->created_by === null || ! is_string($ownerModel) || ! is_subclass_of($ownerModel, Model::class)) {
            return;
        }

        $owner = $ownerModel::query()->find($endpoint->created_by);

        if (! $owner instanceof Model || ! method_exists($owner, 'notify')) {
            return;
        }

        $endpointHost = parse_url($endpoint->url, PHP_URL_HOST) ?: 'an unknown host';

        $owner->notify(new WebhookEndpointDisabledNotification(
            $endpoint->public_id,
            $endpoint->name ?? $endpointHost,
            $endpointHost,
            $event->reason,
            $event->disabledAt,
        ));
    }
}
