<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Qiick\Outbound\Contracts\WebhookEndpointNoticeRecipientsContract;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Notifications\WebhookEndpointDisabledNotification;
use Qiick\Outbound\Tests\Support\NotifiableUser;

/*
 * the maintainer's decision Q2 of 2026-11-22: an automatically disabled endpoint's owner hears
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

it('emails the owner of an endpoint disabled automatically', function (WebhookEndpointDisabledReasonEnum $reason): void {
    $ownerId = ownerUserId();
    $endpoint = endpointOwnedBy($ownerId);

    announceDisabled($endpoint, $reason);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification, array $channels): bool => $channels === ['mail']
        && $notification->endpointPublicId === $endpoint->public_id
        && $notification->endpointHost === 'hooks.example.com'
        && $notification->reason === $reason
        && $notification->endpointPageUrl === null);
    Notification::assertSentTimes(WebhookEndpointDisabledNotification::class, 1);
})->with([WebhookEndpointDisabledReasonEnum::GoneHttp410, WebhookEndpointDisabledReasonEnum::AutoFailure]);

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
    expect(new WebhookEndpointDisabledNotification('id', 'hooks.example.com', WebhookEndpointDisabledReasonEnum::GoneHttp410, CarbonImmutable::now(), null))
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

it('names the host as unknown when the endpoint URL has none, or not a valid host name', function (string $url): void {
    $ownerId = ownerUserId();

    announceDisabled(endpointOwnedBy($ownerId, ['url' => $url]), WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification): bool => $notification->endpointHost === 'an unknown host');
})->with(['not a url', 'https://evil example.com/x', 'https://bad_host!.example.com/']);

it('lowercases the host and keeps an IPv6 literal', function (string $url, string $host): void {
    $ownerId = ownerUserId();

    announceDisabled(endpointOwnedBy($ownerId, ['url' => $url]), WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification): bool => $notification->endpointHost === $host);
})->with([['https://Hooks.EXAMPLE.com/x', 'hooks.example.com'], ['https://[2001:DB8::1]:8443/x', '[2001:db8::1]']]);

it('links the product\'s own endpoint page only when the product configures it', function (): void {
    config()->set('outbound.webhooks.auto_disable.endpoint_url', 'https://app.example.com/settings/webhooks/{endpoint}');
    $ownerId = ownerUserId();
    $endpoint = endpointOwnedBy($ownerId);

    announceDisabled($endpoint, WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification): bool => $notification->endpointPageUrl === "https://app.example.com/settings/webhooks/{$endpoint->public_id}");
});

/*
 * Review of #31: the mail carries no text the tenant wrote. The endpoint is
 * named by its host and public id; the only link is the product's own page.
 * Golden check of both parts of the rendered mail: every line exactly once,
 * no double escaping (`&amp;`, stray backslashes), no name and no link of the
 * tenant's.
 */
it('renders the same, safe mail in its HTML and text parts', function (?string $endpointPageUrl): void {
    $notification = new WebhookEndpointDisabledNotification(
        '0193b3c4-0000-7000-8000-000000000001',
        'hooks.example.com',
        WebhookEndpointDisabledReasonEnum::AutoFailure,
        CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC'),
        $endpointPageUrl,
    );
    $mail = $notification->toMail(new stdClass);
    $htmlPart = (string) $mail->render();
    $textPart = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    $expectedLines = [
        'Webhook deliveries to hooks.example.com (endpoint 0193b3c4-0000-7000-8000-000000000001) stopped at 2026-11-23 10:00:00 UTC.',
        'It has not accepted a webhook for days, and every recent attempt failed.',
        'Fix the receiving end, then enable the endpoint again: its pending deliveries resume.',
    ];

    expect($mail->subject)->toBe('Webhook endpoint disabled: hooks.example.com')
        ->and([...$mail->introLines, ...$mail->outroLines])->toBe($expectedLines)
        ->and($mail->actionUrl)->toBe($endpointPageUrl)
        ->and($mail->actionText)->toBe($endpointPageUrl === null ? null : 'Open the endpoint');

    foreach ([$htmlPart, $textPart] as $renderedPart) {
        foreach ($expectedLines as $expectedLine) {
            expect(substr_count($renderedPart, $expectedLine))->toBe(1);
        }

        expect($renderedPart)->not->toContain('&amp;')->not->toContain('\\');
    }

    if ($endpointPageUrl !== null) {
        expect($htmlPart)->toContain('href="'.$endpointPageUrl.'"')
            ->and($textPart)->toContain($endpointPageUrl);
    }
})->with(['without a page link' => [null], 'with the product\'s page link' => ['https://app.example.com/settings/webhooks/0193b3c4-0000-7000-8000-000000000001']]);

it('never puts the endpoint name in the mail', function (): void {
    $ownerId = ownerUserId();
    endpointOwnedBy($ownerId, ['name' => 'Visit www.evil.example now']);
    $endpoint = WebhookEndpoint::query()->latest('id')->firstOrFail();

    announceDisabled($endpoint, WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, function (WebhookEndpointDisabledNotification $notification): bool {
        $mail = $notification->toMail(new stdClass);

        return ! str_contains((string) $mail->render(), 'evil.example') && ! str_contains((string) $mail->subject, 'evil');
    });
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

it('sends no link when the endpoint page setting is empty', function (): void {
    config()->set('outbound.webhooks.auto_disable.endpoint_url', '');
    $ownerId = ownerUserId();

    announceDisabled(endpointOwnedBy($ownerId), WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification): bool => $notification->endpointPageUrl === null);
});

/*
 * Signaculum adoption: the product chooses the recipients instead of turning
 * notify_owner off.
 */

it('emails the recipients a product binds instead of the creator', function (): void {
    $ownerId = ownerUserId();
    $teammateId = ownerUserId();
    $endpoint = endpointOwnedBy($ownerId);
    app()->instance(WebhookEndpointNoticeRecipientsContract::class, new class($teammateId) implements WebhookEndpointNoticeRecipientsContract
    {
        public function __construct(private int $teammateId) {}

        public function recipientsFor(WebhookEndpoint $endpoint): iterable
        {
            return [NotifiableUser::query()->findOrFail($this->teammateId), Notification::route('mail', 'ops@example.com')];
        }
    });

    announceDisabled($endpoint, WebhookEndpointDisabledReasonEnum::GoneHttp410);

    Notification::assertSentTo(NotifiableUser::query()->findOrFail($teammateId), WebhookEndpointDisabledNotification::class);
    Notification::assertSentOnDemand(WebhookEndpointDisabledNotification::class, fn (WebhookEndpointDisabledNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ops@example.com');
    Notification::assertNotSentTo(NotifiableUser::query()->findOrFail($ownerId), WebhookEndpointDisabledNotification::class);
});

it('emails nobody when the bound recipients are none', function (): void {
    $ownerId = ownerUserId();
    app()->instance(WebhookEndpointNoticeRecipientsContract::class, new class implements WebhookEndpointNoticeRecipientsContract
    {
        public function recipientsFor(WebhookEndpoint $endpoint): iterable
        {
            return [];
        }
    });

    announceDisabled(endpointOwnedBy($ownerId), WebhookEndpointDisabledReasonEnum::AutoFailure);

    Notification::assertNothingSent();
});
