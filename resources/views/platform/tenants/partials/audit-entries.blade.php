@php($showTenant = $showTenant ?? false)

<flux:table data-test="audit-entries">
    <flux:table.columns>
        <flux:table.column>{{ __('When') }}</flux:table.column>
        @if ($showTenant)
            <flux:table.column>{{ __('Tenant') }}</flux:table.column>
        @endif
        <flux:table.column>{{ __('Action') }}</flux:table.column>
        <flux:table.column>{{ __('Platform Admin') }}</flux:table.column>
        <flux:table.column>{{ __('Changes') }}</flux:table.column>
        <flux:table.column>{{ __('Reason') }}</flux:table.column>
    </flux:table.columns>

    <flux:table.rows>
        @forelse ($entries as $entry)
            <flux:table.row :key="$entry->id">
                <flux:table.cell class="whitespace-nowrap">{{ $entry->created_at?->toDayDateTimeString() }}</flux:table.cell>
                @if ($showTenant)
                    <flux:table.cell>
                        <flux:link :href="route('platform.tenants.show', $entry->tenant_id)">{{ $entry->tenant?->name ?? $entry->tenant_id }}</flux:link>
                    </flux:table.cell>
                @endif
                <flux:table.cell>
                    <flux:badge size="sm" inset="top bottom" :color="$entry->action->color()">{{ $entry->action->label() }}</flux:badge>
                </flux:table.cell>
                <flux:table.cell>{{ $entry->actor_email }}</flux:table.cell>
                <flux:table.cell class="whitespace-normal">
                    @foreach (array_unique(array_merge(array_keys($entry->old_values ?? []), array_keys($entry->new_values ?? []))) as $field)
                        @continue($field === 'suspension_reason' || $field === 'suspended_at')
                        <div class="text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400">{{ Str::headline($field) }}:</span>
                            @if (array_key_exists($field, $entry->old_values ?? []))
                                {{ $entry->old_values[$field] ?? '—' }} &rarr;
                            @endif
                            {{ $entry->new_values[$field] ?? '—' }}
                        </div>
                    @endforeach
                </flux:table.cell>
                <flux:table.cell class="whitespace-normal">{{ $entry->reason ?? '—' }}</flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell :colspan="$showTenant ? 6 : 5">{{ __('No administration activity recorded yet.') }}</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
