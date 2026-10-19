<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * End the session of a deactivated user on every tenant request.
     *
     * Runs in the "tenant" middleware group (including Livewire updates), so a user
     * deactivated while logged in, or returning with a "remember me" cookie, loses
     * access on their very next request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('status', __('Your account has been deactivated.'));

            throw new AuthenticationException('Your account has been deactivated.', ['web']);
        }

        return $next($request);
    }
}
