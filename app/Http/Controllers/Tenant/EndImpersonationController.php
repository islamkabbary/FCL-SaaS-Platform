<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ImpersonationEndReason;
use App\Http\Controllers\Controller;
use App\Impersonation\ActiveImpersonation;
use App\Impersonation\Impersonator;
use App\Models\SupportSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "End impersonation" control of the banner.
 */
class EndImpersonationController extends Controller
{
    public function __invoke(Request $request, Impersonator $impersonator): RedirectResponse
    {
        $active = ActiveImpersonation::current($request);

        if ($active === null) {
            return redirect()->route('dashboard');
        }

        $restored = $impersonator->terminate($request, $active->session, ImpersonationEndReason::EndedByActor);

        // The Platform Admin's central session was never touched: send them back to it.
        if ($active->session instanceof SupportSession) {
            return redirect()->away(route('platform.support-sessions.show', $active->session));
        }

        return $restored !== null
            ? redirect()->route('employees.index')->with('status', __('You are back in your own account.'))
            : redirect()->route('login');
    }
}
