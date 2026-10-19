<x-layouts::platform :title="__('Renew subscription')">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.subscriptions.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Renewal') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @php($previous = $quote->previousTerm)

    <div class="mt-4 max-w-2xl">
        <flux:heading size="xl" level="1">{{ __('Annual renewal') }}</flux:heading>
        <flux:subheading>{{ __('The current term ends on :date. Previous terms and charges are kept unchanged.', ['date' => $previous->lastDay()->toFormattedDateString()]) }}</flux:subheading>

        @error('subscription')
            <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="$message" />
        @enderror

        <form method="GET" action="{{ route('platform.subscriptions.renew', $tenant) }}" class="mt-6 flex flex-col gap-6">
            <flux:select name="plan_id" :label="__('Plan for the new term')">
                @foreach ($plans as $option)
                    <flux:select.option :value="$option->id" :selected="$quote->plan->id === $option->id">
                        {{ $option->name }} — {{ $option->format($option->annual_base_fee) }} + {{ __('seat') }} × {{ $option->format($option->annual_seat_price) }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:input name="seats" type="number" min="1" :label="__('Seats for the new term')" :value="$quote->seats" required />
            <div><flux:button type="submit" icon="calculator">{{ __('Update preview') }}</flux:button></div>
        </form>

        <flux:card class="mt-8" data-test="renewal-preview">
            <flux:heading size="lg">{{ __('Preview') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('New term') }}</dt>
                <dd class="sm:col-span-2">{{ __(':start to :end (inclusive)', ['start' => $quote->startsOn->toDateString(), 'end' => $quote->endsOn->subDay()->toDateString()]) }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Seats') }}</dt>
                <dd class="sm:col-span-2">
                    {{ __(':seats (current term: :current, active users: :users)', ['seats' => $quote->seats, 'current' => $previous->seats, 'users' => $quote->activeUsers]) }}
                    @if ($quote->scheduledReduction)
                        <div class="text-xs">{{ __('Includes the approved reduction to :seats seats (request #:id).', ['seats' => $quote->scheduledReduction->seats, 'id' => $quote->scheduledReduction->id]) }}</div>
                    @endif
                </dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Annual charge') }}</dt>
                <dd class="sm:col-span-2 font-medium" data-test="renewal-total">
                    {{ $quote->plan->format($quote->plan->annual_base_fee) }} + {{ $quote->seats }} × {{ $quote->plan->format($quote->plan->annual_seat_price) }} = {{ $quote->plan->format($quote->annualTotal()) }}
                </dd>
            </dl>

            @if ($quote->pricingChanged())
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4" data-test="pricing-changed"
                    :heading="__('The plan or prices differ from the current term.')">
                    <flux:callout.text>
                        {{ __('Current term: :plan, :base + seat × :seat. New term: :newPlan, :newBase + seat × :newSeat. At the current term\'s prices the same seats would cost :old; the new total is :new.', [
                            'plan' => $previous->plan_name,
                            'base' => $previous->format($previous->annual_base_fee),
                            'seat' => $previous->format($previous->annual_seat_price),
                            'newPlan' => $quote->plan->name,
                            'newBase' => $quote->plan->format($quote->plan->annual_base_fee),
                            'newSeat' => $quote->plan->format($quote->plan->annual_seat_price),
                            'old' => $previous->format($quote->previousPricesTotal()),
                            'new' => $quote->plan->format($quote->annualTotal()),
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
                    <input type="hidden" name="plan_id" value="{{ $quote->plan->id }}">
                    <input type="hidden" name="seats" value="{{ $quote->seats }}">
                    @if ($quote->pricingChanged())
                        <flux:checkbox name="accept_pricing_change" value="1" :label="__('I confirm the new plan and prices for this renewal.')" required />
                    @endif
                    <div><flux:button variant="primary" type="submit" data-test="confirm-renewal-button">{{ __('Confirm renewal and create the charge') }}</flux:button></div>
                </form>
            @endif
        </flux:card>
    </div>
</x-layouts::platform>
