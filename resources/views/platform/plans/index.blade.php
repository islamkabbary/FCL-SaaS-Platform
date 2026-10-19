<x-layouts::platform :title="__('Plans')">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Subscription plans') }}</flux:heading>
            <flux:subheading>{{ __('Fixed annual plans: annual total = base fee + purchased seats × seat price.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('platform.plans.create')" data-test="create-plan-link">{{ __('New plan') }}</flux:button>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Plan') }}</flux:table.column>
            <flux:table.column>{{ __('Billing') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Annual base fee') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Annual price per seat') }}</flux:table.column>
            <flux:table.column>{{ __('Subscriptions') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($plans as $plan)
                <flux:table.row :key="$plan->id">
                    <flux:table.cell variant="strong">
                        {{ $plan->name }}
                        @if ($plan->description)
                            <div class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $plan->description }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ __('Annual') }} · {{ $plan->currency }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $plan->format($plan->annual_base_fee) }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $plan->format($plan->annual_seat_price) }}</flux:table.cell>
                    <flux:table.cell>{{ $usage[$plan->id] ?? 0 }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="$plan->is_active ? 'green' : 'zinc'">{{ $plan->is_active ? __('Active') : __('Archived') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" variant="ghost" inset="top bottom" :href="route('platform.plans.edit', $plan)">{{ __('Edit') }}</flux:button>
                            <form method="POST" action="{{ $plan->is_active ? route('platform.plans.archive', $plan) : route('platform.plans.activate', $plan) }}">
                                @csrf
                                <flux:button size="sm" variant="ghost" inset="top bottom" type="submit">{{ $plan->is_active ? __('Archive') : __('Activate') }}</flux:button>
                            </form>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7">{{ __('No plans yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:text class="mt-4 text-xs">{{ __('Changing or archiving a plan never changes existing subscription terms or charges: each term keeps the prices it was created with.') }}</flux:text>
</x-layouts::platform>
