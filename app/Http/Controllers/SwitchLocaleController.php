<?php

namespace App\Http\Controllers;

use App\Actions\Localization\UpdateLocalePreference;
use App\Http\Middleware\SetLocale;
use App\Impersonation\ActiveImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The language switcher, on the central domain and on tenants.
 */
class SwitchLocaleController extends Controller
{
    public function __invoke(Request $request, UpdateLocalePreference $updateLocalePreference): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config()->array('app.supported_locales')))],
        ]);

        $request->session()->put(SetLocale::SessionKey, $validated['locale']);

        $account = SetLocale::authenticatedAccount();

        // An impersonated session never changes the preference of the account it acts as.
        if ($account !== null && ActiveImpersonation::current($request) === null) {
            $updateLocalePreference->handle($account, $validated['locale']);
        }

        return back(fallback: '/');
    }
}
