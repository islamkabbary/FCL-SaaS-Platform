<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\RecordPayment;
use App\Billing\Money;
use App\Exceptions\InvalidSubscriptionChange;
use App\Http\Controllers\Controller;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

class ChargeController extends Controller
{
    public function index(Request $request): View
    {
        $outstandingOnly = $request->boolean('outstanding');

        return view('platform.charges.index', [
            'charges' => BillingCharge::query()
                ->with('tenant')
                ->withPaidTotal()
                ->when($outstandingOnly, fn ($query) => $query->outstanding())
                ->latest('issued_on')
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'outstandingOnly' => $outstandingOnly,
        ]);
    }

    public function show(BillingCharge $charge): View
    {
        return view('platform.charges.show', [
            'charge' => $charge->load(['tenant.domains', 'lines', 'payments.recorder', 'term'])->loadSum('payments', 'amount'),
            // A fresh token per rendered form: submitting the same form twice records one payment.
            'submissionToken' => (string) Str::uuid(),
        ]);
    }

    public function storePayment(Request $request, BillingCharge $charge, RecordPayment $recordPayment): RedirectResponse
    {
        $input = $request->validate([
            'amount' => ['required', 'string', 'regex:'.Money::pattern($charge->currency)],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'submission_token' => ['required', 'uuid'],
        ], [
            'amount.regex' => __('Enter a positive amount with at most :decimals decimal places.', ['decimals' => Money::decimals($charge->currency)]),
        ]);

        try {
            $recordPayment->handle(
                $this->admin($request),
                $charge,
                Money::parse($input['amount'], $charge->currency),
                CarbonImmutable::parse($input['paid_on']),
                $input['reference'] ?? null,
                $input['note'] ?? null,
                $input['submission_token'],
            );
        } catch (InvalidSubscriptionChange|InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['amount' => $exception->getMessage()]);
        }

        return redirect()->route('platform.charges.show', $charge)->with('status', __('Payment recorded.'));
    }

    protected function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }
}
