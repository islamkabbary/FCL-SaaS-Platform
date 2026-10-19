<x-layouts::platform :title="__('Activate subscription')">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.subscriptions.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Activate subscription') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 max-w-2xl">
        <flux:heading size="xl" level="1">{{ __('Activate an annual subscription') }}</flux:heading>
        <flux:subheading>{{ __('Choose a plan, the purchased seats and the start date, preview the term and its charge, then confirm.') }}</flux:subheading>

        @error('subscription')
            <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="$message" />
        @enderror

        <form method="GET" action="{{ route('platform.subscriptions.activate', $tenant) }}" class="mt-6 flex flex-col gap-6">
            <flux:select name="plan_id" :label="__('Plan')" required>
                <flux:select.option value="">{{ __('Select a plan') }}</flux:select.option>
                @foreach ($plans as $option)
                    <flux:select.option :value="$option->id" :selected="$plan?->id === $option->id">
                        {{ $option->name }} — {{ $option->format($option->annual_base_fee) }} + {{ __('seat') }} × {{ $option->format($option->annual_seat_price) }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input name="seats" type="number" min="1" :label="__('Purchased seats')" :value="$seats" :description="__('Every active user, including Tenant Admins, uses one seat.')" required />
            <flux:input name="starts_on" type="date" :label="__('Start date')" :value="$startsOn" :max="now()->toDateString()" :description="__('Today or earlier. The term runs one calendar year from this date.')" required />

            <div>
                <flux:button type="submit" icon="calculator" data-test="preview-activation-button">{{ __('Preview') }}</flux:button>
            </div>
        </form>

        @if ($quote)
            <flux:card class="mt-8" data-test="activation-preview">
                <flux:heading size="lg">{{ __('Preview') }}</flux:heading>
                <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Term') }}</dt>
                    <dd class="sm:col-span-2">{{ __(':start to :end (inclusive), renews on :renewal', ['start' => $quote['starts_on']->toDateString(), 'end' => $quote['ends_on']->subDay()->toDateString(), 'renewal' => $quote['ends_on']->toDateString()]) }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Active users now') }}</dt>
                    <dd class="sm:col-span-2">{{ $quote['active_users'] }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Annual charge') }}</dt>
                    <dd class="sm:col-span-2 font-medium" data-test="annual-total">
                        {{ $plan->format($plan->annual_base_fee) }} + {{ $seats }} × {{ $plan->format($plan->annual_seat_price) }} = {{ $plan->format($quote['annual_total']) }}
                    </dd>
                </dl>

                @if ($quote['errors'] !== [])
                    @foreach ($quote['errors'] as $error)
                        <flux:callout variant="danger" icon="x-circle" class="mt-4" :heading="$error" />
                    @endforeach
                @else
                    <form method="POST" action="{{ route('platform.subscriptions.activate.store', $tenant) }}" class="mt-6">
                        @csrf
                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                        <input type="hidden" name="seats" value="{{ $seats }}">
                        <input type="hidden" name="starts_on" value="{{ $quote['starts_on']->toDateString() }}">
                        <flux:button variant="primary" type="submit" data-test="confirm-activation-button">{{ __('Activate and create the annual charge') }}</flux:button>
                    </form>
                @endif
            </flux:card>
        @endif
    </div>
</x-layouts::platform>
