<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Enums\OutboundTransportErrorKindEnum;
use Qiick\Outbound\Enums\WebhookAttemptStatusEnum;
use Qiick\Outbound\Enums\WebhookAttemptTriggerEnum;
use Qiick\Outbound\Enums\WebhookDeliveryTriggerEnum;
use Qiick\Outbound\Enums\WebhookEndpointDisabledReasonEnum;
use Qiick\Outbound\Enums\WebhookExhaustedReasonEnum;
use Qiick\Outbound\Enums\WebhookRetryPresetEnum;
use Qiick\Outbound\Enums\WebhookSignatureSchemeEnum;
use Qiick\Outbound\Http\OutboundHttpStackData;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\AuthorizedDestination;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Webhooks\SeenWebhookIdStore;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookIdClaim;

/*
 * docs/public-api.md is the frozen 1.0 surface: every class, interface, enum
 * and trait in src/ says whether it belongs to it (@api) or not (@internal),
 * and the page lists every @api one. Adapted from qiick/laravel-http-basics:
 * declarations are found whether or not they have a docblock, and keyed by
 * their fully qualified name.
 */

/**
 * @return array<class-string, string> fully qualified name => the docblock right above it ('' when none)
 */
function sourceClassDocblocks(): array
{
    $docblocks = [];
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        $source = (string) file_get_contents((string) $sourceFile);
        preg_match('/^namespace ([^;]+);/m', $source, $namespace);
        preg_match_all('/(\/\*\*(?:(?!\*\/).)*\*\/\s*\n)?^(?:final |abstract )?(?:readonly )?(?:class|interface|enum|trait) (\w+)/ms', $source, $declarations, PREG_SET_ORDER);

        foreach ($declarations as $declaration) {
            $docblocks[$namespace[1].'\\'.$declaration[2]] = $declaration[1];
        }
    }

    ksort($docblocks);

    return $docblocks;
}

it('finds every declaration in src', function (): void {
    $sourceFiles = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS)));

    expect(sourceClassDocblocks())->toHaveCount(count($sourceFiles));
});

it('marks every class in src as @api or @internal, never both', function (): void {
    foreach (sourceClassDocblocks() as $class => $docblock) {
        $isApi = str_contains($docblock, '@api');
        $isInternal = str_contains($docblock, '@internal');

        expect($isApi xor $isInternal)->toBeTrue("{$class} must be marked either @api or @internal.");
    }
});

it('lists every @api class in docs/public-api.md, and nothing else', function (): void {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/docs/public-api.md');
    preg_match_all('/^### `([^`]+)`\n\n((?:- `\w+` \((?:class|interface|enum|trait)\)\n)+)/m', $page, $sections, PREG_SET_ORDER);
    $listedClasses = [];

    foreach ($sections as [, $namespace, $entries]) {
        preg_match_all('/^- `(\w+)`/m', $entries, $names);

        foreach ($names[1] as $name) {
            $listedClasses[] = $namespace.'\\'.$name;
        }
    }

    sort($listedClasses);
    $apiClasses = array_keys(array_filter(sourceClassDocblocks(), static fn (string $docblock): bool => str_contains($docblock, '@api')));

    expect($listedClasses)->toBe($apiClasses);
});

it('lists all shipped config keys and environment variables', function (): void {
    $root = dirname(__DIR__, 2);
    $path = $root.'/config/outbound.php';
    $defaults = require $path;
    $page = (string) file_get_contents($root.'/docs/public-api.md');
    preg_match_all('/^\| `(outbound\.[^`]+)` \| (?:`(OUTBOUND_\w+)`|—) \|$/m', $page, $documented);
    preg_match_all("/\benv\('(OUTBOUND_\w+)'/", (string) file_get_contents($path), $environment);

    $keys = array_keys(Arr::dot(['outbound' => $defaults]));
    expect($documented[1])->toEqualCanonicalizing($keys)
        ->and(array_values(array_filter($documented[2])))->toEqualCanonicalizing($environment[1]);
});

it('keeps the case names and stored backing values of delivery enums stable', function (string $enum, array $expected): void {
    $backedCases = [];

    foreach ($enum::cases() as $case) {
        $backedCases[$case->name] = $case->value;
    }

    expect($backedCases)->toBe($expected);
})->with([
    [DeliveryStatus::class, [
        'Pending' => 'pending',
        'Delivered' => 'delivered',
        'Failed' => 'failed',
        'Refused' => 'refused',
        'Exhausted' => 'exhausted',
        'Cancelled' => 'cancelled',
    ]],
    [OutboundTransportErrorKindEnum::class, [
        'Timeout' => 'timeout',
        'Tls' => 'tls',
        'Dns' => 'dns',
        'Connection' => 'connection',
    ]],
    [WebhookAttemptStatusEnum::class, [
        'Delivered' => 'delivered',
        'Failed' => 'failed',
        'Refused' => 'refused',
    ]],
    [WebhookAttemptTriggerEnum::class, [
        'Schedule' => 'schedule',
        'Manual' => 'manual',
        'Bulk' => 'bulk',
        'Test' => 'test',
    ]],
    [WebhookDeliveryTriggerEnum::class, [
        'Event' => 'event',
        'Manual' => 'manual',
        'Bulk' => 'bulk',
        'Test' => 'test',
    ]],
    [WebhookEndpointDisabledReasonEnum::class, [
        'Manual' => 'manual',
        'AutoFailure' => 'auto_failure',
        'GoneHttp410' => 'gone_410',
    ]],
    [WebhookExhaustedReasonEnum::class, [
        'MaxAttempts' => 'max_attempts',
        'RetryAfterExceedsCap' => 'retry_after_exceeds_cap',
        'Gone' => 'gone',
        'PayloadExpired' => 'payload_expired',
    ]],
    [WebhookRetryPresetEnum::class, [
        'Standard' => 'standard',
        'Short' => 'short',
    ]],
    [WebhookSignatureSchemeEnum::class, [
        'V1' => 'v1',
        'V2a' => 'v2a',
    ]],
]);

it('keeps the named parameters and return types used by downstream packages', function (
    string $class, string $method, array $names, int $required, string $returnType,
): void {
    $signature = new ReflectionMethod($class, $method);

    expect(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $signature->getParameters()))->toBe($names)
        ->and($signature->getNumberOfRequiredParameters())->toBe($required)
        ->and((string) $signature->getReturnType())->toBe($returnType);
})->with([
    'authorize' => [RemoteUrlGuard::class, 'authorize', ['url', 'options'], 1, AuthorizedDestination::class],
    'try authorize' => [RemoteUrlGuard::class, 'tryAuthorize', ['url', 'options'], 1, 'array'],
    'browser URL' => [RemoteUrlGuard::class, 'assertBrowserUrlIsSafe', ['url', 'options'], 1, 'void'],
    'isolated outbound stack' => [OutboundHttpStackData::class, 'make', ['hostResolver', 'allowPrivateHosts', 'httpsOnly', 'limits', 'httpFactory'], 1, 'self'],
    'claim' => [SeenWebhookIdStore::class, 'claim', ['claim', 'ttlSeconds'], 2, 'bool'],
    'release' => [SeenWebhookIdStore::class, 'release', ['claim'], 1, 'void'],
    'verify' => [StandardWebhookVerifier::class, 'verify', ['rawBody', 'webhookId', 'webhookTimestamp', 'webhookSignatureHeader', 'secrets', 'now', 'dedupeScope'], 7, WebhookIdClaim::class],
    'dispatch' => [WebhookDeliveryCoordinator::class, 'dispatch', ['message', 'scope', 'subjectPublicId'], 1, Collection::class],
]);

it('keeps test-only seams out of the pinned transport constructor', function (): void {
    $parameters = (new ReflectionMethod(PinnedHttpClient::class, '__construct'))->getParameters();

    expect(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))
        ->not->toContain('clock')
        ->not->toContain('transferHandler');
});

it('keeps calls to the internal HTTP fixture factory out of package source', function (): void {
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        expect((string) file_get_contents((string) $sourceFile))->not->toMatch('/(?:::|->)\s*forTesting\s*\(/');
    }
});

it('requires a dedupe store when constructing the verifier', function (): void {
    $parameters = (new ReflectionMethod(StandardWebhookVerifier::class, '__construct'))->getParameters();
    $store = collect($parameters)->first(static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'seenIds');

    expect($store)->toBeInstanceOf(ReflectionParameter::class)
        ->and($store->isOptional())->toBeFalse()
        ->and($store->allowsNull())->toBeFalse();
});
