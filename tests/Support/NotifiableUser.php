<?php

declare(strict_types=1);

namespace Qiick\Outbound\Tests\Support;

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;

/**
 * The product user model of the owner-notification tests, like a Laravel
 * app's `App\Models\User`.
 */
final class NotifiableUser extends User
{
    use Notifiable;

    protected $table = 'users';
}
