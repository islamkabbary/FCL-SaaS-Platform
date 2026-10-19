<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Impersonation\ConsumeSupportHandoff;
use App\Exceptions\ImpersonationRefused;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives the support handoff on the tenant subdomain.
 *
 * Exempt from CSRF verification because the request comes from the central domain's
 * form; the single-use, tenant-bound credential in the body plays that role. It only
 * ever redirects to a fixed tenant route (no redirect target is accepted).
 */
class SupportHandoffController extends Controller
{
    public function __invoke(Request $request, ConsumeSupportHandoff $consumeSupportHandoff): Response|RedirectResponse
    {
        abort_unless(config('impersonation.support.enabled'), 404);

        try {
            $consumeSupportHandoff->handle($request, $request->string('token')->toString());
        } catch (ImpersonationRefused $exception) {
            return response()
                ->view('support-handoff-failed', ['message' => $exception->getMessage()], 403)
                ->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route('dashboard');
    }
}
