<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Notifications\WebhookEndpointDisabledNotification;
use Qiick\Outbound\Tests\Support\NotifiableUser;

/*
 * Luca's Q2 of 2026-11-22: an automatically disabled endpoint's owner hears
 * of it by email. The package notifies `created_by`; a product can turn that
 * off and listen to WebhookEndpointDisabledEvent for its own channels.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    config()->set('auth.providers.users.model', NotifiableUser::class);
    Notification::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function endpointOwnedBy(?int $ownerId, array $attributes = []): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill([
        'url' => 'https://hooks.example.com/receive?token=secret',
        'name' => 'Billing sync',
        'created_by' => $ownerId,
        ...$attributes,
    ]);
    $endpoint->save();

    return $endpoint;
}

function ownerUserId(): int
{
    return (int) DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner-'.bin2hex(random_bytes(4)).'@example.com', 'password' => 'x']);
}

function announceDisabled(WebhookEndpoint $endpoint, WebhookEndpointDisabledReasonEnum $reason): void
{
    event(new WebhookEndpointDisabledEvent($endpoint->public_id, $reason, CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC')));
}

it('emails the owner of an endpoint disabled automatically', function (WebhookEndpointDisabledReasonEnum $reason, string $reasonLine): void {
    $ownerId = ownerUserId();
    $endpoint = endpointOwnedBy($ownerId);

    announceDisabled($endpoint, $reason);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, function (WebhookEndpointDisabledNotification $notification, array $channels) use ($endpoint, $reason, $reasonLine): bool {
        $mail = $notification->toMail(new stdClass);
        $mailText = (string) $mail->render();

        return $channels === ['mail']
            && $notification->endpointPublicId === $endpoint->public_id
            && $notification->reason === $reason
            && $mail->subject === 'Webhook endpoint "Billing sync" disabled'
            && str_contains($mailText, 'Billing sync (hooks.example.com)')
            && str_contains($mailText, $reasonLine)
            && str_contains($mailText, '2026-11-23 10:00:00 UTC')
            && ! str_contains($mailText, 'token=secret')
            && ! str_contains($mailText, '/receive');
    });
    Notification::assertSentTimes(WebhookEndpointDisabledNotification::class, 1);
})->with([
    'gone' => [WebhookEndpointDisabledReasonEnum::GoneHttp410, 'It answered 410 Gone'],
    'failure streak' => [WebhookEndpointDisabledReasonEnum::AutoFailure, 'It has not accepted a webhook for days'],
]);

it('names an unnamed endpoint by its host', function (): void {
    $ownerId = ownerUserId();
    $endpoint = endpointOwnedBy($ownerId, ['name' => null]);

    announceDisabled($endpoint, WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification): bool => $notification->toMail(new stdClass)->subject === 'Webhook endpoint "hooks.example.com" disabled');
});

it('does not email a manual disable, an endpoint without owner, an unknown endpoint, or an owner that cannot be notified', function (): void {
    announceDisabled(endpointOwnedBy(ownerUserId()), WebhookEndpointDisabledReasonEnum::Manual);
    announceDisabled(endpointOwnedBy(null), WebhookEndpointDisabledReasonEnum::GoneHttp410);
    // A deleted owner leaves `created_by` null (null-on-delete), as just above.
    event(new WebhookEndpointDisabledEvent('00000000-0000-7000-8000-000000000000', WebhookEndpointDisabledReasonEnum::GoneHttp410, CarbonImmutable::now()));
    config()->set('auth.providers.users.model', User::class);
    announceDisabled(endpointOwnedBy(ownerUserId()), WebhookEndpointDisabledReasonEnum::GoneHttp410);
    config()->set('auth.providers.users.model', 'App\\Models\\Missing');
    announceDisabled(endpointOwnedBy(ownerUserId()), WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertNothingSent();
});

it('emails the owner of a soft-deleted endpoint too', function (): void {
    $ownerId = ownerUserId();
    $endpoint = endpointOwnedBy($ownerId);
    $endpoint->delete();

    announceDisabled($endpoint, WebhookEndpointDisabledReasonEnum::AutoFailure);

    Notification::assertSentTimes(WebhookEndpointDisabledNotification::class, 1);
});

it('lets a product turn the owner email off and keep the event for its own channels', function (): void {
    config()->set('outbound.webhooks.auto_disable.notify_owner', false);
    $endpoint = endpointOwnedBy(ownerUserId());
    $endpoint->subscriptions()->create(['event_type' => 'invoice.paid']);
    $seenEvents = [];
    Event::listen(WebhookEndpointDisabledEvent::class, function (WebhookEndpointDisabledEvent $event) use (&$seenEvents): void {
        $seenEvents[] = $event->endpointPublicId;
    });

    app(WebhookDeliveryCoordinator::class)->disableEndpoint($endpoint);
    announceDisabled($endpoint, WebhookEndpointDisabledReasonEnum::GoneHttp410);

    expect($seenEvents)->toBe([$endpoint->public_id, $endpoint->public_id]);
    Notification::assertNothingSent();
});

it('queues the email so a worker run does not wait on mail', function (): void {
    expect(new WebhookEndpointDisabledNotification('id', 'Billing sync', 'hooks.example.com', WebhookEndpointDisabledReasonEnum::GoneHttp410, CarbonImmutable::now()))
        ->toBeInstanceOf(ShouldQueue::class);
});

it('emails the owner when the config has no notify_owner key', function (): void {
    config()->set('outbound.webhooks.auto_disable', ['enabled' => true]);
    $ownerId = ownerUserId();

    announceDisabled(endpointOwnedBy($ownerId), WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTimes(WebhookEndpointDisabledNotification::class, 1);
});

it('sends nothing when the user model cannot find the owner', function (): void {
    config()->set('auth.providers.users.model', HiddenUser::class);

    announceDisabled(endpointOwnedBy(ownerUserId()), WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertNothingSent();
});

it('names the host as unknown when the endpoint URL has none', function (): void {
    $ownerId = ownerUserId();

    announceDisabled(endpointOwnedBy($ownerId, ['url' => 'not a url', 'name' => null]), WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification): bool => $notification->endpointHost === 'an unknown host');
});

/**
 * A user model whose scope hides every user, like a product that soft-deletes users.
 */
final class HiddenUser extends User
{
    use Notifiable;

    protected $table = 'users';

    protected static function booted(): void
    {
        self::addGlobalScope('hidden', static function (Builder $query): void {
            $query->whereRaw('1 = 0');
        });
    }
}

/*
 * Review of #31, B1: the endpoint name is tenant text. It reaches the mail as
 * plain text on one line: no Markdown, no link, a bounded length.
 */
it('puts the endpoint name in the mail as plain text, never as a link', function (): void {
    $notification = new WebhookEndpointDisabledNotification(
        'id',
        "[Verify your account](https://evil.example/login)\nSecond **line** <b>x</b> ".str_repeat('a', 200),
        'hooks.example.com',
        WebhookEndpointDisabledReasonEnum::GoneHttp410,
        CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC'),
    );

    $mail = $notification->toMail(new stdClass);
    $renderedMail = (string) $mail->render();

    expect($mail->subject)->not->toContain("\n")
        ->and($mail->subject)->toStartWith('Webhook endpoint "[Verify your account](https://evil.example/login) Second **line** <b>x</b> aaa')
        ->and(mb_strlen($mail->subject))->toBeLessThanOrEqual(130)
        ->and($renderedMail)->not->toContain('href="https://evil.example')
        ->and($renderedMail)->not->toContain('<strong>line</strong>')
        ->and($renderedMail)->not->toContain('<b>x</b>')
        ->and($renderedMail)->toContain('Verify your account')
        ->and($mail->introLines[0])->toContain('…');
});

it('keeps a short, ordinary name as it is', function (): void {
    $notification = new WebhookEndpointDisabledNotification('id', 'Billing sync', 'hooks.example.com', WebhookEndpointDisabledReasonEnum::GoneHttp410, CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC'));

    expect((string) $notification->toMail(new stdClass)->render())->toContain('Billing sync (hooks.example.com)');
});

it('writes the first line in reading order, with the name and host escaped', function (): void {
    $notification = new WebhookEndpointDisabledNotification('id', '  Billing sync  ', 'hooks.example.com', WebhookEndpointDisabledReasonEnum::GoneHttp410, CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC'));
    $mail = $notification->toMail(new stdClass);

    expect($mail->subject)->toBe('Webhook endpoint "Billing sync" disabled')
        ->and($mail->introLines[0])->toBe('Webhook deliveries to Billing sync \\(hooks\\.example\\.com\\) stopped at 2026-11-23 10:00:00 UTC.');
});

it('keeps a name of 80 characters whole and cuts a longer one to 80 with an ellipsis', function (): void {
    $subjectOf = fn (string $name): string => (new WebhookEndpointDisabledNotification('id', $name, 'hooks.example.com', WebhookEndpointDisabledReasonEnum::GoneHttp410, CarbonImmutable::now()))->toMail(new stdClass)->subject;

    expect($subjectOf(str_repeat('a', 80)))->toBe('Webhook endpoint "'.str_repeat('a', 80).'" disabled')
        ->and($subjectOf(str_repeat('a', 81)))->toBe('Webhook endpoint "'.str_repeat('a', 79).'…" disabled');
});
