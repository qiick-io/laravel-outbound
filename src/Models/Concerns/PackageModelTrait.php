<?php

declare(strict_types=1);

namespace Qiick\Outbound\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Shared rules of the package's webhook models.
 *
 * - A UUID v7 `public_id` is filled on create and is the route key, while the
 *   bigint primary key stays auto-incrementing. Public ids are the only ids
 *   that leave the database (2026-11-20 fleet contracts: UUIDs on the wire),
 *   so `id`, foreign keys and sensitive columns are hidden from arrays.
 * - Mass assignment takes an explicit, minimal allow-list per model; every
 *   other attribute (ids, lifecycle state, secret bytes, the stored body) is
 *   written by the package itself or through a dedicated model method.
 * - A new instance starts with the table's column defaults, so it reads like
 *   a stored row before it is saved or refreshed.
 *
 * The rules are applied in Eloquent's `initialize{Trait}` hook instead of
 * property defaults, so each one is executed code the mutation suite checks.
 */
trait PackageModelTrait
{
    use HasUuids;

    /**
     * The only attributes `fill()` and `create()` accept.
     *
     * @return list<string>
     */
    abstract protected function fillableAttributes(): array;

    /**
     * Foreign keys and sensitive columns hidden from arrays and JSON, on top of `id`.
     *
     * @return list<string>
     */
    abstract protected function privateAttributes(): array;

    /**
     * The table's column defaults, as stored values.
     *
     * @return array<string, bool|int|string>
     */
    abstract protected function databaseDefaults(): array;

    public function initializePackageModelTrait(): void
    {
        $this->fillable = $this->fillableAttributes();
        $this->guarded = ['*'];
        $this->hidden = ['id', ...$this->privateAttributes()];
        $this->attributes = [...$this->databaseDefaults(), ...$this->attributes];
    }

    /**
     * Only `public_id` is generated: listing `id` here would turn the primary
     * key into a non-incrementing string key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
