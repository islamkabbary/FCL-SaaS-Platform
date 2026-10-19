<?php

namespace App\Http\Middleware;

use App\Impersonation\ActiveImpersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Force users holding a temporary password to set a new one before continuing.
     *
     * Not applied to an impersonated session: the password belongs to the target, who
     * must change it themselves, and changing it is refused while impersonating.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs('security.edit') && ActiveImpersonation::current($request) === null) {
            return redirect()->route('security.edit')
                ->with('status', __('Please change your temporary password before continuing.'));
        }

        return $next($request);
    }
}
