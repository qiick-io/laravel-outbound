<?php

declare(strict_types=1);

/*
 * docs/public-api.md is the frozen 1.0 surface: every class, interface and enum in
 * src/ says whether it belongs to it (@api) or not (@internal), and the page lists
 * every @api one.
 */

/**
 * @return array<string, string> class short name => the docblock right above it
 */
function sourceClassDocblocks(): array
{
    $docblocks = [];
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        $source = (string) file_get_contents((string) $sourceFile);

        if (preg_match('/(\/\*\*(?:(?!\*\/).)*\*\/)\s*\n(?:final |abstract )?(?:readonly )?(?:class|interface|enum|trait) (\w+)/s', $source, $match) === 1) {
            $docblocks[$match[2]] = $match[1];
        }
    }

    ksort($docblocks);

    return $docblocks;
}

it('marks every class in src as @api or @internal, never both', function (): void {
    foreach (sourceClassDocblocks() as $class => $docblock) {
        $isApi = str_contains($docblock, '@api');
        $isInternal = str_contains($docblock, '@internal');

        expect($isApi xor $isInternal)->toBeTrue("{$class} must be marked either @api or @internal.");
    }
});

it('lists every @api class in docs/public-api.md, and nothing else', function (): void {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/docs/public-api.md');
    preg_match_all('/^- `(\w+)` \((?:class|interface|enum)\)$/m', $page, $listed);

    $apiClasses = array_keys(array_filter(sourceClassDocblocks(), static fn (string $docblock): bool => str_contains($docblock, '@api')));
    $listedClasses = $listed[1];
    sort($listedClasses);

    expect($listedClasses)->toBe($apiClasses);
});
