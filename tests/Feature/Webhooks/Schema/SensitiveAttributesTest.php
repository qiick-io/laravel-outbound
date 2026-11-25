<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Qiick\Outbound\Delivery\DuplicateWebhookDeliveryException;
use Qiick\Outbound\Delivery\WebhookDeliveryConcurrencyException;
use Qiick\Outbound\Delivery\WebhookDeliveryStorageException;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;
use Qiick\Outbound\Models\WebhookSubscription;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookSecret;

/*
 * Mass assignment takes an explicit, minimal allow-list per model. Secret
 * bytes and the stored body (with its hash) are set only through the models'
 * dedicated methods, and never leave the model through arrays or JSON.
 */

/**
 * @return array<string, array{class-string<Model>, list<string>}>
 */
function sensitiveAttributesByModel(): array
{
    return [
        'endpoint secret' => [WebhookEndpointSecret::class, ['secret']],
        'delivery' => [WebhookDelivery::class, ['payload', 'payload_hash']],
    ];
}

it('allows mass assignment of the listed attributes only', function (string $modelClass, array $fillableAttributes): void {
    /** @var Model $model */
    $model = new $modelClass;

    expect($model->getFillable())->toBe($fillableAttributes)
        ->and($model->getGuarded())->toBe(['*'])
        ->and($model->isFillable('id'))->toBeFalse()
        ->and($model->isFillable('public_id'))->toBeFalse();
})->with([
    'endpoint' => [WebhookEndpoint::class, [
        'name', 'description', 'url', 'payload_version', 'legacy_profile', 'https_only',
        'timeout_seconds', 'max_concurrency',
        'retry_preset', 'max_attempts', 'headers', 'metadata',
    ]],
    'endpoint secret' => [WebhookEndpointSecret::class, ['metadata']],
    'subscription' => [WebhookSubscription::class, ['event_type']],
    'delivery' => [WebhookDelivery::class, [
        'event_id', 'event_type', 'occurred_at', 'payload_version', 'max_attempts', 'trigger', 'metadata',
    ]],
    'attempt' => [WebhookDeliveryAttempt::class, []],
]);

it('never mass-assigns the product scope column, which decides the tenant', function (): void {
    config()->set('outbound.webhooks.scope_column', 'project_id');
    $endpoint = (new WebhookEndpoint)->fill(['url' => 'https://hooks.example.com/receive', 'project_id' => 7]);

    expect($endpoint->isFillable('project_id'))->toBeFalse()
        ->and($endpoint->getAttributes())->not->toHaveKey('project_id')
        ->and($endpoint->url)->toBe('https://hooks.example.com/receive')
        ->and($endpoint->forceFill(['project_id' => 7])->getAttribute('project_id'))->toBe(7);
});

it('never mass-assigns a sensitive attribute', function (string $modelClass, array $sensitiveAttributes): void {
    foreach ($sensitiveAttributes as $sensitiveAttribute) {
        /** @var Model $model */
        $model = new $modelClass;
        $model->fill([$sensitiveAttribute => 'whsec_forged']);

        expect($model->isFillable($sensitiveAttribute))->toBeFalse()
            ->and($model->getAttributes())->not->toHaveKey($sensitiveAttribute);
    }
})->with(sensitiveAttributesByModel());

it('never shows a sensitive attribute in arrays or JSON', function (string $modelClass, array $sensitiveAttributes): void {
    /** @var Model $model */
    $model = new $modelClass;
    $model->forceFill(array_fill_keys($sensitiveAttributes, 'sensitive-value'));

    foreach ($sensitiveAttributes as $sensitiveAttribute) {
        expect($model->toArray())->not->toHaveKey($sensitiveAttribute)
            ->and(json_decode($model->toJson(), true))->not->toHaveKey($sensitiveAttribute)
            ->and($model->toJson())->not->toContain('sensitive-value');
    }
})->with(sensitiveAttributesByModel());

it('stores a secret only through useSecret, in canonical whsec_ form', function (): void {
    $secret = WebhookSecret::parseAllowingLegacyRaw(base64_encode(str_repeat('k', 32)), allowRaw: true);
    $endpointSecret = (new WebhookEndpointSecret)->forceFill(['is_legacy_raw' => true]);

    expect($endpointSecret->useSecret($secret))->toBe($endpointSecret)
        ->and($endpointSecret->secret)->toBe($secret->serialized())
        ->and($endpointSecret->is_legacy_raw)->toBeFalse();
});

it('stores a body only through usePayload, with its SHA-256 hash and byte length', function (): void {
    $delivery = new WebhookDelivery;
    $rawBody = '{"type":"invoice.paid","data":{"name":"Zoë"}}';

    expect($delivery->usePayload($rawBody))->toBe($delivery)
        ->and($delivery->payload)->toBe($rawBody)
        ->and($delivery->payload_hash)->toBe(hash('sha256', $rawBody))
        ->and($delivery->payload_bytes)->toBe(strlen($rawBody))
        ->and($delivery->payload_bytes)->not->toBe(mb_strlen($rawBody))
        ->and($delivery->isFillable('payload_bytes'))->toBeFalse();
});

it('encrypts the custom request headers at rest and never shows them', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.example.com/receive',
        'headers' => ['Authorization' => 'Bearer TOKEN123'],
    ]);
    $storedHeaders = (string) DB::table('webhook_endpoints')->value('headers');

    expect($storedHeaders)->not->toContain('TOKEN123')
        ->and(WebhookEndpoint::query()->findOrFail($endpoint->id)->headers)->toBe(['Authorization' => 'Bearer TOKEN123'])
        ->and($endpoint->toArray())->not->toHaveKey('headers')
        ->and($endpoint->toJson())->not->toContain('TOKEN123');
});

it('refuses to replicate a signing secret, which would copy the key and the primary flag', function (string $replicateMethod): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $secret = $endpoint->secrets()->make()->useSecret(WebhookSecret::generate())->forceFill(['is_primary' => true]);
    $secret->save();

    expect(fn () => $secret->{$replicateMethod}())
        ->toThrow(WebhookException::class, 'Webhook secrets cannot be replicated; issue or rotate them with SecretRotator.')
        ->and(WebhookEndpointSecret::query()->count())->toBe(1);
})->with(['replicate', 'replicateQuietly']);

/**
 * @param  array<string, mixed>  $attributes
 */
function unsavedDelivery(WebhookEndpoint $endpoint, string $rawBody, array $attributes = []): WebhookDelivery
{
    return $endpoint->deliveries()->make()->usePayload($rawBody)->forceFill([
        'event_id' => 'msg_1',
        'event_type' => 'invoice.paid',
        'occurred_at' => '2026-11-23 10:00:00',
        'payload_version' => '1',
        'max_attempts' => 10,
        'payload_expires_at' => '2026-12-23 10:00:00',
        ...$attributes,
    ]);
}

it('keeps the body hash and every other binding out of a failed delivery save', function (): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $rawBody = '{"type":"ping"}';
    $delivery = unsavedDelivery($endpoint, $rawBody, ['event_type' => null]);

    try {
        $delivery->save();
        $thrown = null;
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    $chainMessages = [];
    for ($link = $thrown; $link !== null; $link = $link->getPrevious()) {
        $chainMessages[] = $link->getMessage();
    }

    expect($thrown)->toBeInstanceOf(WebhookDeliveryStorageException::class)
        ->and($thrown)->not->toBeInstanceOf(DuplicateWebhookDeliveryException::class)
        ->and($thrown?->getMessage())->toMatch('/^Webhook delivery could not be saved \(SQLSTATE \w{5}\)\.$/')
        ->and($thrown?->getPrevious())->toBeNull()
        ->and(implode("\n", $chainMessages))->not->toContain(hash('sha256', $rawBody))->not->toContain('msg_1')
        ->and((string) $thrown)->not->toContain(hash('sha256', $rawBody));
});

it('reports a second delivery of the same event to the same endpoint as a duplicate, without bindings', function (): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    unsavedDelivery($endpoint, '{"type":"ping"}')->save();

    try {
        unsavedDelivery($endpoint, '{"type":"ping"}')->save();
        $thrown = null;
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(DuplicateWebhookDeliveryException::class)
        ->and($thrown?->getMessage())->toMatch('/^Webhook delivery could not be saved \(SQLSTATE \w{5}\)\.$/')
        ->and($thrown?->getPrevious())->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(1);
});

it('refuses a duplicate inside a product transaction without aborting it', function (): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $transactionsBegun = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactionsBegun): void {
        $transactionsBegun++;
    });

    $deliveriesAfterTheDuplicate = DB::transaction(function () use ($endpoint): int {
        unsavedDelivery($endpoint, '{"type":"ping"}')->save();

        try {
            unsavedDelivery($endpoint, '{"type":"ping"}')->save();
        } catch (DuplicateWebhookDeliveryException) {
            // The product carries on with its own transaction.
        }

        return WebhookDelivery::query()->count();
    });

    // One for the product's transaction, one savepoint per save.
    expect($deliveriesAfterTheDuplicate)->toBe(1)
        ->and($transactionsBegun)->toBe(3);
});

it('saves and updates a delivery normally', function (): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $delivery = unsavedDelivery($endpoint, '{"type":"ping"}');

    expect($delivery->save())->toBeTrue()
        ->and($delivery->forceFill(['attempts_count' => 1])->save())->toBeTrue()
        ->and(WebhookDelivery::query()->sole()->attempts_count)->toBe(1);
});

/**
 * Makes the next delivery insert fail the way a deadlock does, with the
 * bindings (the body hash among them) in the QueryException message.
 */
function failNextDeliveryInsertWithDeadlock(): void
{
    WebhookDelivery::creating(function (WebhookDelivery $delivery): void {
        throw new QueryException(
            'testing',
            'insert into "webhook_deliveries" ("payload_hash", "event_id") values (?, ?)',
            [$delivery->payload_hash, $delivery->event_id],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'),
        );
    });
}

it('keeps the bindings out of a deadlock, inside or outside a product transaction, and lets Laravel retry it', function (bool $isInProductTransaction): void {
    $endpoint = WebhookEndpoint::query()->create(['url' => 'https://hooks.example.com/receive']);
    $rawBody = '{"type":"ping"}';
    failNextDeliveryInsertWithDeadlock();
    $save = fn () => unsavedDelivery($endpoint, $rawBody)->save();

    try {
        $isInProductTransaction ? DB::transaction($save) : $save();
        $thrown = null;
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    $chain = '';
    $chainClasses = [];
    for ($link = $thrown; $link !== null; $link = $link->getPrevious()) {
        $chain .= $link->getMessage()."\n";
        $chainClasses[] = $link::class;
    }

    // Inside a transaction Laravel wraps a concurrency error once more, around ours.
    expect($thrown)->toBeInstanceOf(DeadlockException::class)
        ->and($thrown?->getCode())->toBe(40001)
        ->and($thrown?->getMessage())->toBe('Webhook delivery could not be saved: deadlock or serialization failure (SQLSTATE 40001).')
        ->and($chainClasses)->toBe($isInProductTransaction
            ? [DeadlockException::class, WebhookDeliveryConcurrencyException::class]
            : [WebhookDeliveryConcurrencyException::class])
        ->and($chain)->not->toContain(hash('sha256', $rawBody))->not->toContain('msg_1')
        ->and((string) $thrown)->not->toContain(hash('sha256', $rawBody));
})->with(['outside a transaction' => [false], 'inside a product transaction' => [true]]);

it('is a concurrency error by Laravel\'s own rule, so DB::transaction retries it', function (): void {
    $detector = new class
    {
        use DetectsConcurrencyErrors;

        public function detects(Throwable $exception): bool
        {
            return $this->causedByConcurrencyError($exception);
        }
    };
    $lockWaitTimeout = new QueryException('testing', 'insert', ['secret-hash'], new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
    $duplicate = new UniqueConstraintViolationException('testing', 'insert', ['secret-hash'], new PDOException('SQLSTATE[23000]: duplicate'));

    expect($detector->detects(WebhookDeliveryStorageException::withoutBindings($lockWaitTimeout)))->toBeTrue()
        ->and(WebhookDeliveryStorageException::withoutBindings($lockWaitTimeout))->toBeInstanceOf(WebhookDeliveryConcurrencyException::class)
        ->and($detector->detects(WebhookDeliveryStorageException::withoutBindings($duplicate)))->toBeFalse();
});

it('keeps the QueryException out of stack-trace arguments', function (): void {
    $queryException = new QueryException('testing', 'insert', ['secret-hash'], new PDOException('SQLSTATE[23000]: duplicate'));
    $previousSetting = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        $storageException = WebhookDeliveryStorageException::withoutBindings($queryException);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previousSetting);
    }

    expect($storageException->getTrace()[0]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
});
