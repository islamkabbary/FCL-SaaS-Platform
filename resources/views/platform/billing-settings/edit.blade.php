<x-layouts::platform :title="__('Price per user')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Price per user') }}</flux:heading>
        <flux:subheading>{{ __('The monthly price of one user. New subscriptions and renewals use it; a term keeps the price it started with until it renews, including for users added during the year.') }}</flux:subheading>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
        @endif

        <flux:card class="mt-6">
            <flux:heading size="lg">{{ __('How a subscription is charged') }}</flux:heading>
            <ul class="mt-3 list-disc space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                <li>{{ __('Setup fee, once per user: price × 12 × users when the subscription starts.') }}</li>
                <li>{{ __('Users added during the year: price × remaining months × added users. The month already started counts in full.') }}</li>
                <li>{{ __('Monthly charge: price × users, issued automatically at the start of every month.') }}</li>
                <li>{{ __('Renewal: no new setup fee, except for users above the most that ever paid one.') }}</li>
            </ul>
        </flux:card>

        <form method="POST" action="{{ route('platform.billing-settings.update') }}" class="mt-6 flex flex-col gap-6">
            @csrf
            @method('PUT')

            <flux:select name="currency" :label="__('Currency')" :description="__('Choose deliberately: no currency is assumed and amounts are never converted. A subscription keeps the currency it started with.')" required>
                <flux:select.option value="">{{ __('Select a currency') }}</flux:select.option>
                @foreach ($currencies as $currency)
                    <flux:select.option :value="$currency" :selected="old('currency', $setting?->currency) === $currency">{{ $currency }} ({{ trans_choice(':count decimal|:count decimals', App\Billing\Money::decimals($currency)) }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input name="seat_price" :label="__('Price per user per month')" inputmode="decimal" dir="ltr"
                :value="old('seat_price', $setting ? App\Billing\Money::toDecimal($setting->seat_price, $setting->currency) : null)" required />

            <div>
                <flux:button variant="primary" type="submit" data-test="save-billing-settings-button">{{ __('Save price') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::platform>
