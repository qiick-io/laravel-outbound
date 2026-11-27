<?php

declare(strict_types=1);

it('keeps every named security checklist reference tied to a test', function (): void {
    $root = dirname(__DIR__, 2);
    $checklist = (string) file_get_contents($root.'/docs/security-checklist.md');
    preg_match_all('/\x60(tests\/[^\x60]+\.php)\x60 › \x60([^\x60]+)\x60/u', $checklist, $references, PREG_SET_ORDER);
    expect($references)->not->toBeEmpty();

    foreach ($references as [, $path, $name]) {
        expect(is_file($root.'/'.$path))->toBeTrue($path);
        $source = (string) file_get_contents($root.'/'.$path);
        $singleQuoted = preg_quote(addcslashes($name, "\\'"), '/');
        $doubleQuoted = preg_quote(addcslashes($name, '\\\\"'), '/');

        expect($source)->toMatch("/\b(?:it|test)\(\s*(?:'{$singleQuoted}'|\"{$doubleQuoted}\")/", "{$path} must contain the test {$name}.");
    }
});

it('uses PHP hash_equals for signature comparison (O10)', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Webhooks/StandardWebhookVerifier.php');
    $code = array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => ! $token->isIgnorable());
    $names = array_map(static fn (PhpToken $token): string => $token->text, $code);

    expect(implode('', $names))->toContain('hash_equals(');
});
