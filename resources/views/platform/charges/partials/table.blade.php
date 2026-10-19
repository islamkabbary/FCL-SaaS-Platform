@php($showTenant = $showTenant ?? true)

<flux:table class="mt-4" data-test="charges-table">
    <flux:table.columns>
        <flux:table.column>#</flux:table.column>
        <flux:table.column>{{ __('Issued') }}</flux:table.column>
        @if ($showTenant)
            <flux:table.column>{{ __('Tenant') }}</flux:table.column>
        @endif
        <flux:table.column>{{ __('Description') }}</flux:table.column>
        <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
        <flux:table.column align="end">{{ __('Balance') }}</flux:table.column>
        <flux:table.column>{{ __('Status') }}</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($charges as $charge)
            <flux:table.row :key="$charge->id">
                <flux:table.cell><flux:link :href="route('platform.charges.show', $charge)">{{ $charge->id }}</flux:link></flux:table.cell>
                <flux:table.cell>{{ $charge->issued_on->toDateString() }}</flux:table.cell>
                @if ($showTenant)
                    <flux:table.cell>{{ $charge->tenant?->name }}</flux:table.cell>
                @endif
                <flux:table.cell class="whitespace-normal">{{ $charge->description }}</flux:table.cell>
                <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($charge->amount) }}</flux:table.cell>
                <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($charge->balance()) }}</flux:table.cell>
                <flux:table.cell><flux:badge size="sm" inset="top bottom" :color="$charge->status()->color()">{{ $charge->status()->label() }}</flux:badge></flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell :colspan="$showTenant ? 7 : 6">{{ __('No charges.') }}</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
