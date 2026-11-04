<?php

namespace App\Concerns;

use App\Actions\Users\GenerateUsername;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An account that signs in with its username or its email address.
 *
 * Every new account gets a unique username generated from its name, whichever way it is
 * created. A username never contains "@", so a login value can only match one of the two.
 */
trait HasUsername
{
    protected static function bootHasUsername(): void
    {
        static::creating(function (Model $account): void {
            if (blank($account->getAttribute('username'))) {
                $account->setAttribute('username', app(GenerateUsername::class)->handle(static::class, (string) $account->getAttribute('name')));
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWhereLogin(Builder $query, string $login): void
    {
        $query->where(fn (Builder $query) => $query->where('email', $login)->orWhere('username', $login));
    }
}
