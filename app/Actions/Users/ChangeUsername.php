<?php

namespace App\Actions\Users;

use App\Enums\AccountAuditAction;
use App\Models\TenantAuditLog;
use App\Models\User;
use App\Rules\ValidUsername;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A user's one change of their generated username, audited in the tenant database.
 * The row lock makes two simultaneous requests count as two changes, so the second fails.
 */
class ChangeUsername
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, string $username): User
    {
        Gate::forUser($user)->authorize('changeUsername', $user);

        $username = Str::lower(trim($username));

        Validator::make(['username' => $username], [
            'username' => ['required', 'string', new ValidUsername, Rule::unique(User::class, 'username')],
        ])->validate();

        try {
            return DB::transaction(function () use ($user, $username): User {
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->username_changed_at !== null) {
                    throw ValidationException::withMessages(['username' => __('The username can only be changed once.')]);
                }

                $previous = $locked->username;
                $locked->forceFill(['username' => $username, 'username_changed_at' => now()])->save();

                TenantAuditLog::record(
                    AccountAuditAction::UsernameChanged,
                    $locked,
                    $locked,
                    oldValues: ['username' => $previous],
                    newValues: ['username' => $username],
                );

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => __('validation.unique', ['attribute' => __('validation.attributes.username')])]);
        }
    }
}
