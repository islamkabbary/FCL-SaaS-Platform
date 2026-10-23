<?php

namespace App\Auth;

use Illuminate\Support\Facades\Hash;

/**
 * Spends the same time as a real password check when the account does not exist, so the
 * response time of a failed login does not reveal which email addresses have an account.
 *
 * The hash is of a random value nobody knows, at the default bcrypt cost (12).
 */
final class DummyPasswordCheck
{
    public const Hash = '$2y$12$/faO.uAX0tgL8JDooi3FLeL13XSkedTliQV1z2aPyisBSDHF6.B0C';

    public static function run(string $password): void
    {
        Hash::check($password, self::Hash);
    }
}
