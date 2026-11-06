<x-layouts::platform :title="__('Renew subscription')">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.subscriptions.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Renewal') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @php($previous = $quote->previousTerm)

    <div class="mt-4 max-w-2xl">
        <flux:heading size="xl" level="1">{{ __('Annual renewal') }}</flux:heading>
        <flux:subheading>{{ __('The current term ends on :date. Previous terms and charges are kept unchanged.', ['date' => $previous->lastDay()->translatedFormat(__('M j, Y'))]) }}</flux:subheading>

        @error('subscription')
            <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="$message" />
        @enderror

        <form method="GET" action="{{ route('platform.subscriptions.renew', $tenant) }}" class="mt-6 flex flex-col gap-6">
            <flux:input name="seats" type="number" min="1" :label="__('Users for the new term')" :value="$quote->seats" required />
            <div><flux:button type="submit" icon="calculator">{{ __('Update preview') }}</flux:button></div>
        </form>

        <flux:card class="mt-8" data-test="renewal-preview">
            <flux:heading size="lg">{{ __('Preview') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('New term') }}</dt>
                <dd class="sm:col-span-2">{{ __(':start to :end (inclusive)', ['start' => $quote->startsOn->toDateString(), 'end' => $quote->endsOn->subDay()->toDateString()]) }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Users') }}</dt>
                <dd class="sm:col-span-2">
                    {{ __(':seats (current term: :current, active users: :users)', ['seats' => $quote->seats, 'current' => $previous->seats, 'users' => $quote->activeUsers]) }}
                    @if ($quote->scheduledReduction)
                        <div class="text-xs">{{ __('Includes the approved reduction to :seats seats (request #:id).', ['seats' => $quote->scheduledReduction->seats, 'id' => $quote->scheduledReduction->id]) }}</div>
                    @endif
                </dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Monthly charge') }}</dt>
                <dd class="sm:col-span-2 font-medium" data-test="renewal-total">
                    <bdi dir="ltr">{{ $quote->format($quote->seatPrice) }} × {{ $quote->seats }} = {{ $quote->format($quote->monthlyFee()) }}</bdi>
                </dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Setup fee') }}</dt>
                <dd class="sm:col-span-2" data-test="renewal-setup-fee">
                    @if ($quote->setupSeats > 0)
                        <bdi dir="ltr">{{ $quote->format($quote->seatPrice) }} × 12 × {{ $quote->setupSeats }} = {{ $quote->format($quote->setupFee()) }}</bdi>
                        <div class="text-xs">{{ __('Only the :seats users above the :paid that already paid a setup fee.', ['seats' => $quote->setupSeats, 'paid' => $quote->seats - $quote->setupSeats]) }}</div>
                    @else
                        {{ __('None: every user already paid a setup fee.') }}
                    @endif
                </dd>
            </dl>

            @if ($quote->pricingChanged())
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4" data-test="pricing-changed"
                    :heading="__('The price per user differs from the current term.')">
                    <flux:callout.text>
                        {{ __('Current term: :old per user. New term: :new per user. The monthly charge for the same users goes from :oldTotal to :newTotal.', [
                            'old' => $previous->format($previous->seat_price),
                            'new' => $quote->format($quote->seatPrice),
                            'oldTotal' => $quote->format($quote->previousMonthlyFee()),
                            'newTotal' => $quote->format($quote->monthlyFee()),
                        ]) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if (! $quote->isConfirmable())
                @foreach ($quote->errors as $error)
                    <flux:callout variant="danger" icon="x-circle" class="mt-4" :heading="$error" />
                @endforeach
            @else
                <form method="POST" action="{{ route('platform.subscriptions.renew.store', $tenant) }}" class="mt-6 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="seats" value="{{ $quote->seats }}">
                    <input type="hidden" name="expected_total" value="{{ $quote->monthlyFee() }}">
                    <input type="hidden" name="expected_previous_seats" value="{{ $previous->seats }}">
                    <input type="hidden" name="expected_reduction_id" value="{{ $quote->scheduledReduction->id ?? 0 }}">
                    @if ($quote->setupSeats > 0)
                        <flux:input name="discount_percent" inputmode="decimal" dir="ltr" :label="__('Discount on the setup fee (%)')" :value="old('discount_percent')" :description="__('Optional, from 0 to 100 with up to two decimals.')" />
                    @endif
                    @if ($quote->pricingChanged())
                        <flux:checkbox name="accept_pricing_change" value="1" :label="__('I confirm the new price per user for this renewal.')" required />
                    @endif
                    <div><flux:button variant="primary" type="submit" data-test="confirm-renewal-button">{{ __('Confirm renewal') }}</flux:button></div>
                </form>
            @endif
        </flux:card>
    </div>
</x-layouts::platform>
