<?php

namespace App\Actions\Billing;

use App\Billing\Money;
use App\Enums\PlatformAuditAction;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\BillingPayment;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment received outside the platform against one charge.
 *
 * The charge row is locked while the balance is checked, so concurrent payments can
 * never exceed it; the form's submission token makes a double submission record the
 * payment once. Payments are never edited or deleted.
 */
class RecordPayment
{
    /**
     * @throws InvalidSubscriptionChange
     */
    public function handle(PlatformAdmin $admin, BillingCharge $charge, int $amount, CarbonImmutable $paidOn, ?string $reference, ?string $note, string $submissionToken): BillingPayment
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $charge, $amount, $paidOn, $reference, $note, $submissionToken): BillingPayment {
            $charge = BillingCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            $existing = BillingPayment::query()->where('submission_token', $submissionToken)->first();

            if ($existing !== null) {
                // A token belongs to one charge's form. Reused for another charge, returning the
                // existing payment would report a payment that was never recorded for this one.
                if ($existing->billing_charge_id !== $charge->id) {
                    throw new InvalidSubscriptionChange(__('This payment form was already used for another charge. Reload the page and enter the payment again.'));
                }

                // The same form was submitted twice: the payment is already recorded.
                return $existing;
            }

            $balance = $charge->amount - (int) BillingPayment::query()->where('billing_charge_id', $charge->id)->sum('amount');

            if ($amount < 1) {
                throw new InvalidSubscriptionChange(__('The payment amount must be greater than zero.'));
            }

            if ($amount > $balance) {
                throw new InvalidSubscriptionChange(__('The payment exceeds the outstanding balance of :balance.', ['balance' => Money::format($balance, $charge->currency)]));
            }

            if ($paidOn->greaterThan(CarbonImmutable::today())) {
                throw new InvalidSubscriptionChange(__('The payment date cannot be in the future.'));
            }

            $payment = BillingPayment::query()->create([
                'billing_charge_id' => $charge->id,
                'tenant_id' => $charge->tenant_id,
                'currency' => $charge->currency,
                'amount' => $amount,
                'paid_on' => $paidOn,
                'reference' => $reference,
                'note' => $note,
                'submission_token' => $submissionToken,
                'recorded_by' => $admin->id,
            ]);

            PlatformAuditLog::record($admin, $charge->tenant, PlatformAuditAction::PaymentRecorded, [
                'charge_id' => $charge->id,
                'balance' => $balance,
            ], [
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency' => $charge->currency,
                'paid_on' => $paidOn->toDateString(),
                'balance' => $balance - $amount,
                'reference' => $reference,
            ]);

            return $payment;
        });
    }
}
