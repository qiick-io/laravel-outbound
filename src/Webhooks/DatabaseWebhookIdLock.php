<?php

declare(strict_types=1);

namespace Qiick\Outbound\Webhooks;

use Illuminate\Cache\DatabaseLock;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Lottery;

/**
 * Protects the database lock's insert-or-takeover sequence in a caller transaction.
 *
 * @internal
 */
final class DatabaseWebhookIdLock extends DatabaseLock
{
    public static function acquireClaim(DatabaseLock $lock): bool
    {
        $connection = $lock->connection;
        if ($connection->transactionLevel() === 0) {
            return $lock->get() === true;
        }

        // Laravel 12 catches the duplicate insert inside acquire(). On
        // PostgreSQL that leaves the caller's transaction unusable. Protect
        // only the insert so a duplicate rolls back before the takeover query.
        try {
            $isAcquired = $connection->transaction(fn (): bool => $connection->table($lock->table)->insert([
                'key' => $lock->name,
                'owner' => $lock->owner,
                'expiration' => $lock->expiresAt(),
            ]));
        } catch (UniqueConstraintViolationException) {
            $isAcquired = $connection->table($lock->table)
                ->where('key', $lock->name)
                ->where(fn (Builder $query): Builder => $query->where('owner', $lock->owner)->orWhere('expiration', '<=', $lock->currentTime()))
                ->update([
                    'owner' => $lock->owner,
                    'expiration' => $lock->expiresAt(),
                ]) > 0;
        }

        $lottery = $lock->lottery ?? [];
        if (count($lottery) === 2 && Lottery::odds(...$lottery)->choose() === true) {
            $connection->table($lock->table)->where('expiration', '<=', $lock->currentTime())->delete();
        }

        return $isAcquired;
    }
}
