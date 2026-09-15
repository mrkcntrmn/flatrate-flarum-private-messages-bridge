<?php

namespace Neoncube\FlarumPrivateMessages\Tests;

use Flarum\User\Guest;
use Flarum\User\User;

/**
 * Construct Users without relying on Eloquent mass-assignment fillable rules.
 * Works with both the bootstrap stub User and real flarum/core User.
 */
final class TestUsers
{
    public static function of($id = null): User
    {
        if ($id === null && class_exists(Guest::class)) {
            return new Guest();
        }

        $user = new User();
        $user->id = $id;

        return $user;
    }
}
