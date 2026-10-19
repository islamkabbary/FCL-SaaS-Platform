<x-layouts::platform :title="__('Subscriptions')">
    <flux:heading size="xl" level="1">{{ __('Subscriptions') }}</flux:heading>
    <flux:subheading>{{ __('Subscription state of every tenant. Independent of provisioning and of manual suspension.') }}</flux:subheading>

    <form method="GET" action="{{ route('platform.subscriptions.index') }}" class="mt-6 flex items-end gap-2">
        <flux:select name="status" :label="__('Subscription')" class="sm:max-w-56">
            <flux:select.option value="">{{ __('Any') }}</flux:select.option>
            @foreach (App\Http\Controllers\Platform\SubscriptionController::filters() as $value => $label)
                <flux:select.option :value="$value" :selected="$filter === $value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="filled">{{ __('Filter') }}</flux:button>
    </form>

    @php
        $today = Carbon\CarbonImmutable::today();
    @endphp

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Company') }}</flux:table.column>
            <flux:table.column>{{ __('Subscription') }}</flux:table.column>
            <flux:table.column>{{ __('Plan') }}</flux:table.column>
            <flux:table.column>{{ __('Seats') }}</flux:table.column>
            <flux:table.column>{{ __('Renewal date') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($tenants as $tenant)
                @php
                    $terms = $tenant->subscription?->terms ?? collect();
                    $current = $terms->first(fn ($term) => $term->covers($today));
                    $latest = $terms->last();
                    $status = $tenant->subscription === null
                        ? ($tenant->subscription_required ? App\Enums\SubscriptionStatus::None : App\Enums\SubscriptionStatus::Legacy)
                        : ($current ? App\Enums\SubscriptionStatus::Active : App\Enums\SubscriptionStatus::Expired);
                @endphp
                <flux:table.row :key="$tenant->id">
                    <flux:table.cell variant="strong">
                        {{ $tenant->name }}
                        <div class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $tenant->primaryDomain()?->domain }} · {{ $tenant->status->label() }}@if ($tenant->isSuspended()) · {{ __('Suspended') }}@endif</div>
                    </flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" inset="top bottom" :color="$status->color()">{{ $status->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ ($current ?? $latest)?->plan_name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $current?->seats ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $latest?->ends_on->toFormattedDateString() ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" inset="top bottom" icon-trailing="chevron-right" :href="route('platform.subscriptions.show', $tenant)">{{ __('Manage') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No tenants match.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-6">{{ $tenants->links() }}</div>
</x-layouts::platform>
