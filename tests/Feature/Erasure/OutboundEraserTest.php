<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryConcurrencyException;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Erasure\ErasureReport;
use Qiick\Outbound\Erasure\OrganisationScopeUnsupportedException;
use Qiick\Outbound\Erasure\OutboundEraser;
use Qiick\Outbound\Events\SubjectErasedEvent;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Webhooks\WebhookMessage;

/*
 * The package's own erasure rules, beside the fleet suite in tests/Erasure:
 * outbound always anonymises (endpoints and the delivery log belong to the
 * organisation), cancels the pending deliveries the product queued about the
 * person, and drops the payload of every delivery about them.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-26 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function erasureUserId(): int
{
    return (int) DB::table('users')->insertGetId(['name' => 'Person', 'email' => 'person-'.Str::random(8).'@example.com', 'password' => 'x']);
}

function erasureEndpoint(?int $createdBy = null): WebhookEndpoint
{
    $endpoint = (new WebhookEndpoint)->forceFill(['url' => 'https://hooks.example.com/receive', 'created_by' => $createdBy]);
    $endpoint->save();
    $endpoint->subscriptions()->create(['event_type' => 'person.updated']);

    return $endpoint;
}

function erasureDelivery(string $messageId, ?string $subjectPublicId, array $changes = []): WebhookDelivery
{
    app(WebhookDeliveryCoordinator::class)->dispatch(
        new WebhookMessage($messageId, 'person.updated', new DateTimeImmutable('2026-11-26 09:00:00'), ['email' => 'person@example.com']),
        subjectPublicId: $subjectPublicId,
    );
    $delivery = WebhookDelivery::query()->where('event_id', $messageId)->sole();
    $delivery->forceFill($changes)->save();

    return $delivery->refresh();
}

/**
 * @param  list<string>  $publicIds
 */
function eraseSubject(string $userId, string $userPublicId, array $publicIds = [], bool $isDryRun = false, string $mode = OutboundEraser::ANONYMISE): ErasureReport
{
    return app(OutboundEraser::class)->erase($userId, $userPublicId, $mode, $publicIds, ['person@example.com'], dryRun: $isDryRun);
}

it('cancels the pending deliveries about the person and drops the payload of every delivery about them', function (): void {
    $userPublicId = (string) Str::uuid();
    $apiKeyPublicId = (string) Str::uuid();
    erasureEndpoint();
    $pending = erasureDelivery('msg_pending', $userPublicId);
    $delivered = erasureDelivery('msg_delivered', $apiKeyPublicId, ['status' => DeliveryStatus::Delivered, 'next_attempt_at' => null]);
    $someoneElse = erasureDelivery('msg_other', (string) Str::uuid());
    $unattributed = erasureDelivery('msg_unattributed', null);
    CarbonImmutable::setTestNow('2026-11-26 11:00:00');

    $report = eraseSubject('100001', $userPublicId, [$apiKeyPublicId]);

    expect($pending->refresh())
        ->status->toBe(DeliveryStatus::Cancelled)
        ->payload->toBeNull()
        ->subject_public_id->toBeNull()
        ->next_attempt_at->toBeNull()
        ->and($delivered->refresh())
        ->status->toBe(DeliveryStatus::Delivered)
        ->payload->toBeNull()
        ->subject_public_id->toBeNull()
        ->updated_at->toEqual(CarbonImmutable::now())
        ->and($someoneElse->refresh())
        ->status->toBe(DeliveryStatus::Pending)
        ->payload->not->toBeNull()
        ->subject_public_id->not->toBeNull()
        ->and($unattributed->refresh()->payload)->not->toBeNull()
        ->and($report->stores['webhook_deliveries'])->toBe(['deleted' => 0, 'anonymised' => 2, 'kept' => 0, 'reason' => null]);
});

it('unlinks the person from the endpoints they created and the attempts and redeliveries they asked for', function (): void {
    $userId = erasureUserId();
    $otherUserId = erasureUserId();
    $endpoint = erasureEndpoint($userId);
    $trashedEndpoint = erasureEndpoint($userId);
    $trashedEndpoint->delete();
    $delivery = erasureDelivery('msg_1', null, ['next_attempt_triggered_by' => $userId]);
    $otherDelivery = erasureDelivery('msg_2', null, ['next_attempt_triggered_by' => $otherUserId]);
    $otherEndpoint = erasureEndpoint($otherUserId);
    foreach ([$userId, $userId, $otherUserId] as $attempt => $triggeredBy) {
        $delivery->attempts()->make()->forceFill([
            'attempt' => $attempt + 1, 'triggered_by' => $triggeredBy, 'status' => 'failed', 'webhook_timestamp' => 1,
            'signature_schemes' => 'v1', 'attempted_at' => CarbonImmutable::now(),
        ])->save();
    }

    CarbonImmutable::setTestNow('2026-11-26 11:00:00');

    $report = eraseSubject((string) $userId, (string) Str::uuid(), mode: OutboundEraser::DELETE);

    expect(DB::table('webhook_endpoints')->whereIn('id', [$endpoint->id, $trashedEndpoint->id])->pluck('created_by')->all())->toBe([null, null])
        ->and($endpoint->refresh()->updated_at)->toEqual(CarbonImmutable::now())
        ->and($otherEndpoint->refresh()->created_by)->toBe($otherUserId)
        ->and($delivery->refresh()->next_attempt_triggered_by)->toBeNull()
        ->and($delivery->updated_at)->toEqual(CarbonImmutable::now())
        ->and($delivery->payload)->not->toBeNull()
        ->and($otherDelivery->refresh()->next_attempt_triggered_by)->toBe($otherUserId)
        ->and(DB::table('webhook_delivery_attempts')->orderBy('attempt')->pluck('triggered_by')->all())->toBe([null, null, $otherUserId])
        ->and($report->toArray())->toBe([
            'package' => 'qiick/laravel-outbound',
            'mode' => OutboundEraser::DELETE,
            'dry_run' => false,
            'stores' => [
                'webhook_endpoints' => ['deleted' => 0, 'anonymised' => 2, 'kept' => 0, 'reason' => null],
                'webhook_deliveries' => ['deleted' => 0, 'anonymised' => 1, 'kept' => 0, 'reason' => null],
                'webhook_delivery_attempts' => ['deleted' => 0, 'anonymised' => 2, 'kept' => 0, 'reason' => null],
            ],
        ])
        ->and($report->changed())->toBe(5);
});

it('counts a delivery about the person once when they also asked for its redelivery', function (): void {
    $userId = erasureUserId();
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    erasureDelivery('msg_1', $userPublicId, ['next_attempt_triggered_by' => $userId]);

    $report = eraseSubject((string) $userId, $userPublicId);

    expect($report->stores['webhook_deliveries']['anonymised'])->toBe(1)
        ->and(WebhookDelivery::query()->sole()->next_attempt_triggered_by)->toBeNull();
});

it('changes nothing on a second run, and nothing for a person with no data', function (): void {
    $userId = erasureUserId();
    $userPublicId = (string) Str::uuid();
    erasureEndpoint($userId);
    erasureDelivery('msg_1', $userPublicId, ['next_attempt_triggered_by' => $userId]);

    $firstReport = eraseSubject((string) $userId, $userPublicId);
    $secondReport = eraseSubject((string) $userId, $userPublicId);

    expect($firstReport->changed())->toBe(2)
        ->and($secondReport->changed())->toBe(0)
        ->and(eraseSubject('100009', (string) Str::uuid())->changed())->toBe(0);
});

it('matches the user columns only for a decimal user key, so a textual key never coerces to another user', function (): void {
    $userId = erasureUserId();
    $userPublicId = (string) Str::uuid();
    $endpoint = erasureEndpoint($userId);
    $delivery = erasureDelivery('msg_1', $userPublicId);
    $unattributed = erasureDelivery('msg_2', null);
    erasureEndpoint();
    $unattributed->attempts()->make()->forceFill([
        'attempt' => 1, 'status' => 'failed', 'webhook_timestamp' => 1, 'signature_schemes' => 'v1', 'attempted_at' => CarbonImmutable::now(),
    ])->save();

    foreach (['0'.$userId, $userId.'abc', '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11'] as $textualKey) {
        eraseSubject($textualKey, (string) Str::uuid());
    }

    $report = eraseSubject('user-'.$userId, $userPublicId);

    expect($endpoint->refresh()->created_by)->toBe($userId)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Cancelled)
        ->and($report->stores['webhook_endpoints']['anonymised'])->toBe(0)
        ->and($report->stores['webhook_delivery_attempts']['anonymised'])->toBe(0)
        ->and($report->changed())->toBe(1);
});

it('runs for real unless a dry run is asked for', function (): void {
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    erasureDelivery('msg_1', $userPublicId);

    $report = app(OutboundEraser::class)->erase('100001', $userPublicId, OutboundEraser::ANONYMISE);

    expect($report->dryRun)->toBeFalse()
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Cancelled);
});

it('predicts the real counts on a dry run and writes nothing', function (): void {
    Event::fake([SubjectErasedEvent::class]);
    $userId = erasureUserId();
    $userPublicId = (string) Str::uuid();
    $endpoint = erasureEndpoint($userId);
    $delivery = erasureDelivery('msg_1', $userPublicId);

    $dryReport = eraseSubject((string) $userId, $userPublicId, isDryRun: true);

    expect($dryReport->dryRun)->toBeTrue()
        ->and($endpoint->refresh()->created_by)->toBe($userId)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->payload)->not->toBeNull();
    Event::assertNotDispatched(SubjectErasedEvent::class);

    expect(eraseSubject((string) $userId, $userPublicId)->stores)->toBe($dryReport->stores);
});

it('announces a real run once, after the commit, and not when the caller rolls back', function (): void {
    Event::fake([SubjectErasedEvent::class]);
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    erasureDelivery('msg_1', $userPublicId);

    try {
        DB::transaction(function () use ($userPublicId): void {
            eraseSubject('100001', $userPublicId);

            throw new RuntimeException('The product step failed.');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(SubjectErasedEvent::class);
    expect(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Pending);

    $report = eraseSubject('100001', $userPublicId);

    Event::assertDispatchedTimes(SubjectErasedEvent::class, 1);
    Event::assertDispatched(SubjectErasedEvent::class, fn (SubjectErasedEvent $event): bool => $event->report === $report);
});

it('refuses an organisation-scoped run before it writes', function (): void {
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    erasureDelivery('msg_1', $userPublicId);

    expect(fn () => app(OutboundEraser::class)->erase('100001', $userPublicId, OutboundEraser::DELETE, organisationId: (string) Str::uuid()))
        ->toThrow(OrganisationScopeUnsupportedException::class, 'Outbound erasure is global: endpoints have no organisation.')
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Pending);
});

it('refuses invalid input without echoing it', function (string $mode, string $userPublicId, array $publicIds, array $identifiers, string $message): void {
    expect(fn () => app(OutboundEraser::class)->erase('100001', $userPublicId, $mode, $publicIds, $identifiers))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown mode' => ['purge', '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11', [], [], 'Unknown outbound erasure mode.'],
    'user public id' => ['delete', 'user-42', [], [], 'Outbound erasure public ids must be UUIDs.'],
    'public id' => ['delete', '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11', ['0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a12', 'key-42'], [], 'Outbound erasure public ids must be UUIDs.'],
    'non-string public id' => ['delete', '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11', [42], [], 'Outbound erasure public ids must be UUIDs.'],
    'blank identifier' => ['delete', '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11', [], ['person@example.com', ' '], 'Outbound erasure identifiers must be non-empty strings.'],
    'non-string identifier' => ['delete', '0198c0b6-3c3e-7d3f-9a3e-2f1f3b9b6a11', [], [42], 'Outbound erasure identifiers must be non-empty strings.'],
]);

it('rethrows a database failure without its bindings', function (): void {
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    erasureDelivery('msg_1', $userPublicId);
    DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
        throw new QueryException('testing', $query, $bindings, new PDOException('Connection lost.'));
    });

    expect(fn () => eraseSubject('100001', $userPublicId))
        ->toThrow(function (RuntimeException $exception) use ($userPublicId): void {
            expect($exception)->not->toBeInstanceOf(QueryException::class)
                ->and($exception->getMessage())->toBe('Outbound erasure database operation failed.')
                ->not->toContain($userPublicId);
        });
});

it('scrubs the receiver diagnostics of every attempt of a delivery about the person, whoever triggered it', function (): void {
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    $aboutThePerson = erasureDelivery('msg_1', $userPublicId);
    $someoneElses = erasureDelivery('msg_2', (string) Str::uuid());
    $diagnostics = ['response_headers' => ['x-request-id' => 'customer-4711'], 'request_id' => 'customer-4711', 'response_excerpt' => 'Hello Ada'];

    foreach ([$aboutThePerson, $someoneElses] as $delivery) {
        $delivery->attempts()->make()->forceFill([
            'attempt' => 1, 'status' => 'failed', 'webhook_timestamp' => 1, 'signature_schemes' => 'v1',
            'attempted_at' => CarbonImmutable::now(), ...$diagnostics,
        ])->save();
    }

    $report = eraseSubject('100001', $userPublicId);

    expect($aboutThePerson->attempts()->sole()->only(['response_headers', 'request_id', 'response_excerpt']))
        ->toBe(['response_headers' => null, 'request_id' => null, 'response_excerpt' => null])
        ->and($someoneElses->attempts()->sole()->only(['response_headers', 'request_id', 'response_excerpt']))->toBe($diagnostics)
        ->and($report->stores['webhook_delivery_attempts']['anonymised'])->toBe(1);
});

it('finds a delivery about the person whatever the case of its UUID', function (): void {
    $userPublicId = (string) Str::uuid();
    $apiKeyPublicId = (string) Str::uuid();
    erasureEndpoint();
    $byUserPublicId = erasureDelivery('msg_1', Str::upper($userPublicId));
    $byApiKey = erasureDelivery('msg_2', $apiKeyPublicId);

    eraseSubject('100001', $userPublicId, [Str::upper($apiKeyPublicId)]);

    expect($byUserPublicId->refresh()->status)->toBe(DeliveryStatus::Cancelled)
        ->and($byApiKey->refresh()->status)->toBe(DeliveryStatus::Cancelled);
});

it('rethrows a deadlock inside the caller transaction as a concurrency error without bindings', function (): void {
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    erasureDelivery('msg_1', $userPublicId);
    DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
        throw new QueryException('testing', $query, $bindings, new PDOException('Deadlock found when trying to get lock', 40001));
    });

    expect(fn () => DB::transaction(fn () => eraseSubject('100001', $userPublicId)))
        ->toThrow(function (DeadlockException $exception) use ($userPublicId): void {
            // The caller's transaction wraps the eraser's sanitised deadlock again.
            expect($exception->getMessage())->not->toContain($userPublicId)->not->toContain('100001')
                ->and($exception->getPrevious())->toBeInstanceOf(WebhookDeliveryConcurrencyException::class)
                ->and($exception->getPrevious()?->getPrevious())->toBeNull();
        });
});

it('announces a committed run even when a transaction on another connection rolls back', function (): void {
    Event::fake([SubjectErasedEvent::class]);
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $userPublicId = (string) Str::uuid();
    erasureEndpoint();
    erasureDelivery('msg_1', $userPublicId);

    try {
        DB::connection('secondary')->transaction(function () use ($userPublicId): void {
            eraseSubject('100001', $userPublicId);

            throw new RuntimeException('The product step failed.');
        });
    } catch (RuntimeException) {
    }

    expect(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Cancelled);
    Event::assertDispatchedTimes(SubjectErasedEvent::class, 1);
});
