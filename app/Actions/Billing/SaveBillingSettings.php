<?php

namespace App\Actions\Billing;

use App\Billing\Money;
use App\Enums\PlatformAuditAction;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingSetting;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the default price per user and month.
 *
 * The new price applies to subscriptions activated and renewed afterwards. Existing terms
 * keep their snapshot for the rest of their year, including seats added to them later.
 */
class SaveBillingSettings
{
    /**
     * @throws AuthorizationException
     * @throws InvalidSubscriptionChange
     */
    public function handle(PlatformAdmin $admin, string $currency, int $seatPrice): BillingSetting
    {
        Gate::forUser($admin)->authorize('manage-billing');

        $currency = strtoupper($currency);

        if (! in_array($currency, Money::currencies(), true)) {
            throw new InvalidSubscriptionChange(__('Choose a supported currency.'));
        }

        if ($seatPrice < 0 || $seatPrice > config('billing.max_price_minor')) {
            throw new InvalidSubscriptionChange(__('The amount is too large.'));
        }

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $currency, $seatPrice): BillingSetting {
            $setting = BillingSetting::query()->oldest('id')->lockForUpdate()->first() ?? new BillingSetting;
            $old = $setting->exists ? ['currency' => $setting->currency, 'seat_price' => $setting->seat_price] : null;

            $setting->forceFill(['currency' => $currency, 'seat_price' => $seatPrice, 'updated_by' => $admin->id]);

            if ($setting->exists && ! $setting->isDirty(['currency', 'seat_price'])) {
                return $setting;
            }

            $setting->save();

            PlatformAuditLog::record($admin, null, PlatformAuditAction::BillingSettingsUpdated, $old, [
                'currency' => $currency,
                'seat_price' => $seatPrice,
            ]);

            return $setting;
        });
    }
}
