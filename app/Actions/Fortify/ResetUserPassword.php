<?php

namespace App\Actions\Fortify;

use App\Actions\Auth\EndOtherSessions;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(protected EndOtherSessions $endOtherSessions) {}

    /**
     * Validate and reset the user's forgotten password, then end every session the user
     * has (the reset happens signed out, so none of them is the current one).
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        $this->endOtherSessions->handle($user);
    }
}
