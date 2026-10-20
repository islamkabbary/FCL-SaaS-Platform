<?php

use App\Actions\Billing\RequestSeatChange;
use App\Billing\Pricing;
use App\Billing\SeatGuard;
use App\Billing\SeatUsage;
use App\Enums\SeatRequestType;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantPermission;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\SeatChangeRequest;
use App\Models\SubscriptionTerm;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The company's read-only view of its subscription, seats and billing history, with
 * seat-change requests. Every record is read from the central database scoped to the
 * current tenant; prices are never taken from the browser.
 */
new #[Title('Subscription & billing')] class extends Component {
    public string $increaseSeats = '';

    public string $reductionSeats = '';

    public string $note = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(TenantPermission::BillingView->value), 403);
    }

    #[Computed]
    public function status(): SubscriptionStatus
    {
        return tenant()->subscriptionStatus();
    }

    #[Computed]
    public function subscription(): ?TenantSubscription
    {
        return TenantSubscription::query()->where('tenant_id', tenant()->getTenantKey())->first();
    }

    #[Computed]
    public function currentTerm(): ?SubscriptionTerm
    {
        return $this->subscription?->termOn();
    }

    /**
     * @return Collection<int, SubscriptionTerm>
     */
    #[Computed]
    public function terms(): Collection
    {
        return SubscriptionTerm::query()->where('tenant_id', tenant()->getTenantKey())->orderByDesc('sequence')->get();
    }

    #[Computed]
    public function seatUsage(): SeatUsage
    {
        return app(SeatGuard::class)->usage();
    }

    /**
     * @return Collection<int, SeatChangeRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return SeatChangeRequest::query()->where('tenant_id', tenant()->getTenantKey())->latest()->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function pendingRequest(): ?SeatChangeRequest
    {
        return $this->requests->firstWhere('status', App\Enums\SeatRequestStatus::Pending);
    }

    #[Computed]
    public function scheduledReduction(): ?SeatChangeRequest
    {
        return SeatChangeRequest::query()->where('tenant_id', tenant()->getTenantKey())->scheduledReduction()->latest('reviewed_at')->first();
    }

    /**
     * @return Collection<int, BillingCharge>
     */
    #[Computed]
    public function charges(): Collection
    {
        return BillingCharge::query()
            ->where('tenant_id', tenant()->getTenantKey())
            ->withPaidTotal()
            ->with(['lines', 'payments'])
            ->latest('issued_on')
            ->latest('id')
            ->get();
    }

    /**
     * Estimated prorated charge for the entered number of additional seats, if valid.
     */
    #[Computed]
    public function increaseEstimate(): ?int
    {
        $seats = filter_var($this->increaseSeats, FILTER_VALIDATE_INT);
        $term = $this->currentTerm;

        if ($term === null || $seats === false || $seats < 1 || $seats > config('billing.max_seats')) {
            return null;
        }

        return Pricing::prorate($seats, $term->annual_seat_price, $term->starts_on, $term->ends_on, CarbonImmutable::today())->amount;
    }

    public function requestIncrease(RequestSeatChange $requestSeatChange): void
    {
        $this->validate([
            'increaseSeats' => ['required', 'integer', 'min:1', 'max:'.config('billing.max_seats')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->submit(fn () => $requestSeatChange->handle(Auth::user(), SeatRequestType::Increase, (int) $this->increaseSeats, $this->note ?: null), __('Your request for additional seats was sent for review.'));
    }

    public function requestReduction(RequestSeatChange $requestSeatChange): void
    {
        $this->validate([
            'reductionSeats' => ['required', 'integer', 'min:1', 'max:'.config('billing.max_seats')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->submit(fn () => $requestSeatChange->handle(Auth::user(), SeatRequestType::Reduction, (int) $this->reductionSeats, $this->note ?: null), __('Your reduction request was sent for review. It would apply from the next renewal.'));
    }

    public function cancelRequest(int $requestId, RequestSeatChange $requestSeatChange): void
    {
        $this->submit(fn () => $requestSeatChange->cancel(Auth::user(), $requestId), __('Request cancelled.'));
    }

    protected function submit(Closure $callback, string $success): void
    {
        try {
            $callback();
        } catch (AuthorizationException|InvalidSubscriptionChange $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::modals()->close();
        Flux::toast(variant: 'success', text: $success);
        $this->reset('increaseSeats', 'reductionSeats', 'note');
        unset($this->requests, $this->pendingRequest);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">{{ __('Subscription & billing') }}</flux:heading>
    <flux:subheading>{{ __('Your company\'s annual subscription, seats and billing history.') }}</flux:subheading>

    @php($canManage = auth()->user()->can(App\Enums\TenantPermission::BillingManage->value))

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <flux:text>{{ __('Status') }}</flux:text>
            <flux:badge class="mt-2" :color="$this->status->color()" data-test="subscription-status">{{ $this->status->label() }}</flux:badge>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Purchased seats') }}</flux:text>
            <flux:heading size="xl" class="mt-2 tabular-nums" data-test="purchased-seats">{{ $this->seatUsage->isUnlimited() ? __('Unlimited') : $this->seatUsage->limit }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Active users (seats in use)') }}</flux:text>
            <flux:heading size="xl" class="mt-2 tabular-nums" data-test="used-seats">{{ $this->seatUsage->used }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Available seats') }}</flux:text>
            <flux:heading size="xl" class="mt-2 tabular-nums" data-test="available-seats">{{ $this->seatUsage->isUnlimited() ? '—' : $this->seatUsage->available() }}</flux:heading>
        </flux:card>
    </div>

    @if ($this->seatUsage->overLimit() > 0)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4"
            :heading="__(':count more users are active than purchased seats. No user can be added or reactivated until enough users are deactivated or seats are added.', ['count' => $this->seatUsage->overLimit()])" />
    @endif

    @if ($this->seatUsage->isCappedByLaterTerm())
        <flux:callout icon="information-circle" class="mt-4" data-test="later-term-seats-callout"
            :heading="__('The renewal already confirmed has :seats seats, so no more than :seats users can be active from now on.', ['seats' => $this->seatUsage->confirmedLaterTermSeats])" />
    @endif

    @if ($this->status === App\Enums\SubscriptionStatus::Legacy)
        <flux:callout icon="information-circle" class="mt-6" :heading="__('Your company has not been moved to an annual subscription yet. Access and users are not limited until the platform administrator activates one.')" />
    @elseif ($this->status === App\Enums\SubscriptionStatus::None)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('No subscription has been activated for your company yet. Please contact the platform administrator.')" />
    @elseif ($this->status === App\Enums\SubscriptionStatus::Expired)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" :heading="__('Your annual subscription has expired. Please contact the platform administrator to renew it.')" />
    @endif

    @if ($term = $this->currentTerm)
        <flux:card class="mt-6">
            <flux:heading size="lg" level="2">{{ __('Current term') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Plan') }}</dt>
                <dd class="sm:col-span-3" data-test="plan-name">{{ $term->plan_name }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Term') }}</dt>
                <dd class="sm:col-span-3">{{ __(':start to :end (inclusive)', ['start' => $term->starts_on->toFormattedDateString(), 'end' => $term->lastDay()->toFormattedDateString()]) }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Renewal date') }}</dt>
                <dd class="sm:col-span-3" data-test="renewal-date">{{ $term->ends_on->toFormattedDateString() }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Annual base fee') }}</dt>
                <dd class="sm:col-span-3">{{ $term->format($term->annual_base_fee) }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Annual price per seat') }}</dt>
                <dd class="sm:col-span-3" data-test="seat-price">{{ $term->format($term->annual_seat_price) }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Seats at term start') }}</dt>
                <dd class="sm:col-span-3">{{ $term->initial_seats }} · {{ __('annual total :total', ['total' => $term->format($term->annualTotal())]) }}</dd>
            </dl>

            @if ($this->scheduledReduction)
                <flux:callout icon="calendar" class="mt-4" :heading="__('Approved: :seats seats from the next renewal.', ['seats' => $this->scheduledReduction->seats])" />
            @endif

            @if ($canManage)
                <div class="mt-6 flex flex-wrap gap-2">
                    @if ($this->pendingRequest)
                        <flux:text>{{ __('A request is pending review. You can cancel it below before making another one.') }}</flux:text>
                    @else
                        <flux:modal.trigger name="request-increase">
                            <flux:button variant="primary" icon="plus" data-test="request-increase-button">{{ __('Request additional seats') }}</flux:button>
                        </flux:modal.trigger>
                        <flux:modal.trigger name="request-reduction">
                            <flux:button icon="minus" data-test="request-reduction-button">{{ __('Request fewer seats at renewal') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>
            @endif
        </flux:card>
    @endif

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Seat requests') }}</flux:heading>
    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>{{ __('Requested') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Seats') }}</flux:table.column>
            <flux:table.column>{{ __('Estimate') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->requests as $request)
                <flux:table.row :key="'request-'.$request->id">
                    <flux:table.cell>{{ $request->created_at?->toFormattedDateString() }} · {{ $request->requested_by_name }}</flux:table.cell>
                    <flux:table.cell>{{ $request->type->label() }}</flux:table.cell>
                    <flux:table.cell>{{ $request->type === App\Enums\SeatRequestType::Increase ? '+'.$request->seats : $request->seats }}</flux:table.cell>
                    <flux:table.cell>{{ $request->estimated_amount !== null ? $request->format($request->estimated_amount) : '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge>
                        @if ($request->review_note)
                            <flux:text class="text-xs">{{ $request->review_note }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($canManage && $request->status === App\Enums\SeatRequestStatus::Pending)
                            <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="cancelRequest({{ $request->id }})" wire:confirm="{{ __('Cancel this request?') }}">{{ __('Cancel') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No seat requests yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Billing history') }}</flux:heading>
    <flux:text class="mt-1 text-xs">{{ __('Internal commercial records of your subscription charges and the payments received. These are not tax invoices.') }}</flux:text>
    <flux:table class="mt-4" data-test="billing-history">
        <flux:table.columns>
            <flux:table.column>{{ __('Issued') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Paid') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Balance') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->charges as $charge)
                <flux:table.row :key="'charge-'.$charge->id">
                    <flux:table.cell>{{ $charge->issued_on->toFormattedDateString() }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">
                        {{ $charge->description }}
                        @foreach ($charge->lines as $line)
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $line->description }}: {{ $line->quantity }} × {{ $charge->format($line->unit_amount) }} → {{ $charge->format($line->amount) }}</div>
                        @endforeach
                        @foreach ($charge->payments as $payment)
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Payment :date: :amount', ['date' => $payment->paid_on->toFormattedDateString(), 'amount' => $charge->format($payment->amount)]) }}</div>
                        @endforeach
                    </flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($charge->amount) }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($charge->paidAmount()) }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($charge->balance()) }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" inset="top bottom" :color="$charge->status()->color()">{{ $charge->status()->label() }}</flux:badge></flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No charges yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @if ($this->terms->isNotEmpty())
        <flux:heading size="lg" level="2" class="mt-10">{{ __('Subscription terms') }}</flux:heading>
        <flux:table class="mt-4">
            <flux:table.columns>
                <flux:table.column>{{ __('Term') }}</flux:table.column>
                <flux:table.column>{{ __('Plan') }}</flux:table.column>
                <flux:table.column>{{ __('Seats') }}</flux:table.column>
                <flux:table.column>{{ __('Base fee / seat price') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->terms as $termRow)
                    <flux:table.row :key="'term-'.$termRow->id">
                        <flux:table.cell>{{ $termRow->starts_on->toFormattedDateString() }} – {{ $termRow->lastDay()->toFormattedDateString() }}</flux:table.cell>
                        <flux:table.cell>{{ $termRow->plan_name }}</flux:table.cell>
                        <flux:table.cell>{{ $termRow->seats }}</flux:table.cell>
                        <flux:table.cell>{{ $termRow->format($termRow->annual_base_fee) }} / {{ $termRow->format($termRow->annual_seat_price) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @if ($canManage && $this->currentTerm)
        <flux:modal name="request-increase" class="md:w-lg">
            <form wire:submit="requestIncrease" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Request additional seats') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Additional seats share the current renewal date (:date) and are charged only for the rest of the current term, after approval by the platform administrator.', ['date' => $this->currentTerm->ends_on->toFormattedDateString()]) }}</flux:text>
                </div>

                <flux:input wire:model.live.debounce.300ms="increaseSeats" type="number" min="1" :label="__('Additional seats')" required />

                @if ($this->increaseEstimate !== null)
                    <flux:callout icon="calculator" :heading="__('Estimated charge if approved today: :amount', ['amount' => $this->currentTerm->format($this->increaseEstimate)])">
                        <flux:callout.text>{{ __('The final amount is calculated on the day of approval.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:textarea wire:model="note" :label="__('Note (optional)')" rows="2" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="submit-increase">{{ __('Send request') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="request-reduction" class="md:w-lg">
            <form wire:submit="requestReduction" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Request fewer seats at renewal') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The current term and its charges stay unchanged. If approved, the lower seat count applies from the next renewal. No user is deactivated automatically.') }}</flux:text>
                </div>

                <flux:input wire:model="reductionSeats" type="number" min="1" :max="$this->currentTerm->seats - 1" :label="__('Seats for the next term')" required />
                <flux:textarea wire:model="note" :label="__('Note (optional)')" rows="2" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="submit-reduction">{{ __('Send request') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
