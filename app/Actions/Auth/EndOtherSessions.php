<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EndOtherSessions
{
    /**
     * Sign a user out everywhere else after their password changes.
     *
     * Deletes their stored sessions (tenant database) except the one given, and rotates the
     * remember-me token so no "remember me" cookie issued before the change signs them in
     * again. Without this, whoever signed in with a temporary or leaked password keeps a
     * working session after the owner changes it. The current device keeps its session; its
     * own remember-me cookie stops working, so it signs in again once that session expires.
     */
    public function handle(User $user, ?string $keepSessionId = null): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))
                ->where('user_id', $user->getKey())
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }
}
