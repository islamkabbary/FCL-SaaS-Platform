<x-layouts::platform :title="__('Activate subscription')">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.subscriptions.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Activate subscription') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 max-w-2xl">
        <flux:heading size="xl" level="1">{{ __('Activate a subscription') }}</flux:heading>
        <flux:subheading>{{ __('Choose the users and the start date, preview the setup fee and the monthly charge, then confirm.') }}</flux:subheading>

        @error('subscription')
            <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="$message" />
        @enderror

        @if ($setting === null)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('Set the price per user in the billing settings first.')">
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('platform.billing-settings.edit')">{{ __('Price per user') }}</flux:button>
                </x-slot>
            </flux:callout>
        @else
            <flux:text class="mt-6">
                {{ __('Price per user per month') }}: <bdi dir="ltr" class="font-medium" data-test="seat-price">{{ $setting->format($setting->seat_price) }}</bdi>
            </flux:text>
        @endif

        <form method="GET" action="{{ route('platform.subscriptions.activate', $tenant) }}" class="mt-6 flex flex-col gap-6">
            <flux:input name="seats" type="number" min="1" :label="__('Users')" :value="$seats" :description="__('Every active user, including Super Admins, uses one seat.')" required />
            <flux:input name="starts_on" type="date" :label="__('Start date')" :value="$startsOn" :max="now()->toDateString()" :description="__('Today or earlier. The term runs one calendar year from this date.')" required />
            <flux:input name="discount_percent" inputmode="decimal" dir="ltr" :label="__('Discount on the setup fee (%)')" :value="$discountPercent" :description="__('Optional, from 0 to 100 with up to two decimals.')" />

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
                    @if ($setting)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Setup fee') }}</dt>
                        <dd class="sm:col-span-2 font-medium" data-test="setup-fee">
                            <bdi dir="ltr">{{ $setting->format($quote['seat_price']) }} × 12 × {{ $seats }} = {{ $setting->format($quote['setup_fee']) }}</bdi>
                        </dd>
                        @if ($quote['discount'] > 0)
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Discount') }}</dt>
                            <dd class="sm:col-span-2" data-test="setup-discount">
                                <bdi dir="ltr">{{ $discountPercent }}% = −{{ $setting->format($quote['discount']) }} → {{ $setting->format($quote['setup_fee'] - $quote['discount']) }}</bdi>
                            </dd>
                        @endif
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Monthly charge') }}</dt>
                        <dd class="sm:col-span-2 font-medium" data-test="monthly-fee">
                            <bdi dir="ltr">{{ $setting->format($quote['seat_price']) }} × {{ $seats }} = {{ $setting->format($quote['monthly_fee']) }}</bdi>
                        </dd>
                    @endif
                </dl>

                @if ($quote['errors'] !== [])
                    @foreach ($quote['errors'] as $error)
                        <flux:callout variant="danger" icon="x-circle" class="mt-4" :heading="$error" />
                    @endforeach
                @else
                    <form method="POST" action="{{ route('platform.subscriptions.activate.store', $tenant) }}" class="mt-6">
                        @csrf
                        <input type="hidden" name="seats" value="{{ $seats }}">
                        <input type="hidden" name="starts_on" value="{{ $quote['starts_on']->toDateString() }}">
                        <input type="hidden" name="discount_percent" value="{{ $discountPercent }}">
                        <input type="hidden" name="expected_total" value="{{ $quote['setup_fee'] }}">
                        <flux:button variant="primary" type="submit" data-test="confirm-activation-button">{{ __('Activate and create the setup fee') }}</flux:button>
                    </form>
                @endif
            </flux:card>
        @endif
    </div>
</x-layouts::platform>
