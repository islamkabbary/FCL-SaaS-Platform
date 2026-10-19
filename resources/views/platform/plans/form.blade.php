<x-layouts::platform :title="$plan ? __('Edit plan') : __('New plan')">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.plans.index')">{{ __('Plans') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $plan ? $plan->name : __('New plan') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 max-w-xl">
        <flux:heading size="xl" level="1">{{ $plan ? __('Edit plan') : __('New plan') }}</flux:heading>
        <flux:subheading>{{ __('Annual billing only. Prices apply to terms created from now on; existing terms keep their own prices.') }}</flux:subheading>

        <form method="POST" action="{{ $plan ? route('platform.plans.update', $plan) : route('platform.plans.store') }}" class="mt-6 flex flex-col gap-6">
            @csrf
            @if ($plan)
                @method('PUT')
            @endif

            <flux:input name="name" :label="__('Name')" :value="old('name', $plan?->name)" required />
            <flux:textarea name="description" :label="__('Description (optional)')" rows="2">{{ old('description', $plan?->description) }}</flux:textarea>

            @if ($plan)
                <flux:input :label="__('Currency')" :value="$plan->currency" :description="__('The currency of a plan cannot be changed.')" disabled />
            @else
                <flux:select name="currency" :label="__('Currency')" :description="__('Choose deliberately: no currency is assumed and amounts are never converted.')" required>
                    <flux:select.option value="">{{ __('Select a currency') }}</flux:select.option>
                    @foreach (App\Billing\Money::currencies() as $currency)
                        <flux:select.option :value="$currency" :selected="old('currency') === $currency">{{ $currency }} ({{ trans_choice(':count decimal|:count decimals', App\Billing\Money::decimals($currency)) }})</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input name="annual_base_fee" :label="__('Annual base fee')" inputmode="decimal"
                :value="old('annual_base_fee', $plan ? App\Billing\Money::toDecimal($plan->annual_base_fee, $plan->currency) : null)" required />
            <flux:input name="annual_seat_price" :label="__('Annual price per seat')" inputmode="decimal"
                :value="old('annual_seat_price', $plan ? App\Billing\Money::toDecimal($plan->annual_seat_price, $plan->currency) : null)" required />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="save-plan-button">{{ __('Save plan') }}</flux:button>
                <flux:link :href="route('platform.plans.index')">{{ __('Cancel') }}</flux:link>
            </div>
        </form>
    </div>
</x-layouts::platform>
