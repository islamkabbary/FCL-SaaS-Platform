<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Force users holding a temporary password to set a new one before continuing.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs('security.edit')) {
            return redirect()->route('security.edit')
                ->with('status', __('Please change your temporary password before continuing.'));
        }

        return $next($request);
    }
}
