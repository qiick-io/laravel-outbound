<?php

declare(strict_types=1);

use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;
use Qiick\Outbound\Webhooks\WebhookSigningSet;

it('keeps the secrets and their public ids in the given order', function (): void {
    $primary = WebhookSecret::generate();
    $old = WebhookSecret::generate();

    $signingSet = new WebhookSigningSet(['01J-primary' => $primary, '123' => $old]);

    expect($signingSet->secrets())->toBe([$primary, $old])
        ->and($signingSet->secretPublicIds())->toBe(['01J-primary', '123']);
});

it('refuses an empty signing set', function (): void {
    new WebhookSigningSet([]);
})->throws(WebhookException::class, 'A webhook signing set needs at least one secret.');

it('refuses a value that is not a WebhookSecret', function (): void {
    new WebhookSigningSet(['01J-primary' => 'whsec_not-parsed']);
})->throws(WebhookException::class, 'Webhook secrets must be WebhookSecret instances.');
