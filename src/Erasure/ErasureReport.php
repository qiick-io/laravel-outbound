<?php

declare(strict_types=1);

namespace Qiick\Outbound\Erasure;

/**
 * Counts the effects of one outbound erasure run without identifying the person.
 *
 * @api
 *
 * @phpstan-type StoreCounts array{deleted: int, anonymised: int, kept: int, reason: ?string}
 */
final readonly class ErasureReport
{
    /**
     * @param  array<string, StoreCounts>  $stores
     */
    public function __construct(
        public string $package,
        public string $mode,
        public bool $dryRun,
        public array $stores,
    ) {}

    public function changed(): int
    {
        $changed = 0;

        foreach ($this->stores as $store) {
            $changed += $store['deleted'] + $store['anonymised'];
        }

        return $changed;
    }

    /**
     * @return array{package: string, mode: string, dry_run: bool, stores: array<string, StoreCounts>}
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'mode' => $this->mode,
            'dry_run' => $this->dryRun,
            'stores' => $this->stores,
        ];
    }
}
