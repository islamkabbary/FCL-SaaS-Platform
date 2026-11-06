<?php

namespace App\Actions\Billing;

use App\Billing\Money;
use App\Billing\Pricing;
use App\Enums\PlatformAuditAction;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\BillingPayment;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the percentage discount of a charge (0 removes it) and recomputes the amount due.
 *
 * Allowed only while nothing has been paid on the charge: the charge row is locked, the
 * same lock RecordPayment takes, so a payment and a discount can never interleave. The
 * lines are never changed, so the subtotal always matches them.
 */
class ApplyChargeDiscount
{
    /**
     * @throws AuthorizationException
     * @throws InvalidSubscriptionChange
     */
    public function handle(PlatformAdmin $admin, BillingCharge $charge, int $basisPoints, ?string $reason = null): BillingCharge
    {
        Gate::forUser($admin)->authorize('manage-billing');

        if ($basisPoints < 0 || $basisPoints > Pricing::MaxDiscountBasisPoints) {
            throw new InvalidSubscriptionChange(__('The discount must be between 0% and 100%.'));
        }

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $charge, $basisPoints, $reason): BillingCharge {
            $charge = BillingCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (BillingPayment::query()->where('billing_charge_id', $charge->id)->exists()) {
                throw new InvalidSubscriptionChange(__('A payment was already recorded for this charge, so its discount can no longer change.'));
            }

            if ($charge->discount_basis_points === $basisPoints) {
                return $charge;
            }

            $old = [
                'charge_id' => $charge->id,
                'discount_basis_points' => $charge->discount_basis_points,
                'amount' => Money::format($charge->amount, $charge->currency),
            ];

            $discount = Pricing::discount($charge->subtotal, $basisPoints);

            $charge->forceFill([
                'discount_basis_points' => $basisPoints,
                'discount_amount' => $discount,
                'amount' => $charge->subtotal - $discount,
            ])->save();

            PlatformAuditLog::record($admin, $charge->tenant, PlatformAuditAction::ChargeDiscountApplied, $old, [
                'charge_id' => $charge->id,
                'discount_basis_points' => $basisPoints,
                'amount' => Money::format($charge->amount, $charge->currency),
            ], $reason);

            return $charge;
        });
    }
}
