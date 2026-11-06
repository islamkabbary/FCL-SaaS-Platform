<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\SaveBillingSettings;
use App\Billing\Money;
use App\Exceptions\InvalidSubscriptionChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\BillingSettingsRequest;
use App\Models\BillingSetting;
use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The default price per user and month, used by new subscriptions and renewals.
 */
class BillingSettingsController extends Controller
{
    public function edit(): View
    {
        return view('platform.billing-settings.edit', [
            'setting' => BillingSetting::current(),
            'currencies' => Money::currencies(),
        ]);
    }

    public function update(BillingSettingsRequest $request, SaveBillingSettings $saveBillingSettings): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        try {
            $saveBillingSettings->handle($admin, (string) $request->currency(), $request->seatPrice());
        } catch (InvalidSubscriptionChange $exception) {
            return back()->withInput()->withErrors(['seat_price' => $exception->getMessage()]);
        }

        return redirect()->route('platform.billing-settings.edit')->with('status', __('Price per user saved. Existing subscription terms keep their price until they renew.'));
    }
}
