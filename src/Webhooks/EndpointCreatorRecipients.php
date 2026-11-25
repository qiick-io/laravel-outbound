<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Qiick\Outbound\Contracts\WebhookEndpointNoticeRecipientsContract;
use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * The package's default recipient of an endpoint notice: the user in
 * `created_by`, loaded with the application's user model
 * (`auth.providers.users.model`). None when the endpoint has no creator,
 * the model is not set, or the user cannot be notified.
 *
 * @internal
 */
final class EndpointCreatorRecipients implements WebhookEndpointNoticeRecipientsContract
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function recipientsFor(WebhookEndpoint $endpoint): iterable
    {
        $userModel = $this->config->get('auth.providers.users.model');

        if (! is_string($userModel) || ! is_subclass_of($userModel, Model::class)) {
            return [];
        }

        // No creator finds nobody.
        $creator = $userModel::query()->find($endpoint->created_by);

        return $creator instanceof Model && method_exists($creator, 'notify') ? [$creator] : [];
    }
}
