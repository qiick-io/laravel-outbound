<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
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

it('keeps the delivery status cases and persisted backing values stable', function (): void {
    $backedCases = [];

    foreach (DeliveryStatus::cases() as $case) {
        $backedCases[$case->name] = $case->value;
    }

    expect($backedCases)->toBe([
        'Pending' => 'pending',
        'Delivered' => 'delivered',
        'Failed' => 'failed',
        'Refused' => 'refused',
        'Exhausted' => 'exhausted',
        'Cancelled' => 'cancelled',
    ]);
});

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
    'claim' => [SeenWebhookIdStore::class, 'claim', ['claim', 'ttlSeconds'], 2, 'bool'],
    'release' => [SeenWebhookIdStore::class, 'release', ['claim'], 1, 'void'],
    'verify' => [StandardWebhookVerifier::class, 'verify', ['rawBody', 'webhookId', 'webhookTimestamp', 'webhookSignatureHeader', 'secrets', 'now', 'dedupeScope'], 7, '?'.WebhookIdClaim::class],
    'dispatch' => [WebhookDeliveryCoordinator::class, 'dispatch', ['message', 'scope', 'subjectPublicId'], 1, Collection::class],
]);
