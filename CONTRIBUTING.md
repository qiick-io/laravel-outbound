# Contributing to laravel-outbound

Thanks for helping. A few rules keep the codebase consistent:

1. Open or pick an issue first. One topic per pull request.
2. Branch from `develop` as `<type>/<kebab-slug>` (types: feat, fix, refactor,
   chore, docs, test, perf, ci, build, security).
3. Use Conventional Commits; PR titles follow the same format. Body lines stay
   at or under 100 characters.
4. Open the PR against `develop`. PRs are squash-merged. Never push to
   `develop` or `main` directly; never tag or publish to Packagist from a PR.
5. Before pushing run `composer test` (Pest), `composer lint` (Pint) and
   `composer analyse` (PHPStan).
6. Code style: strict types, `final` by default, `@api` on SemVer surfaces,
   English docblocks that explain *why*, no abbreviations.
7. Security checks are never weakened. A change to the SSRF guard, DNS pin,
   redirect loop, webhook signer/verifier or secret parsing needs a test that
   proves a bad value is refused. Prefer red-first tests; run
   `vendor/bin/pest --mutate` on the mutation targets named in the design.
8. Tests stay deterministic: no live network. Inject `HostResolver`, HTTP
   transport, clock and randomizer fakes.
