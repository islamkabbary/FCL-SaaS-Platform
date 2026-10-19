@php($showTenant = $showTenant ?? false)

<flux:table class="mt-4" data-test="support-sessions">
    <flux:table.columns>
        <flux:table.column>{{ __('Started') }}</flux:table.column>
        @if ($showTenant)
            <flux:table.column>{{ __('Tenant') }}</flux:table.column>
        @endif
        <flux:table.column>{{ __('Platform Admin') }}</flux:table.column>
        <flux:table.column>{{ __('Acting as') }}</flux:table.column>
        <flux:table.column>{{ __('Status') }}</flux:table.column>
        <flux:table.column>{{ __('Reason') }}</flux:table.column>
        <flux:table.column></flux:table.column>
    </flux:table.columns>

    <flux:table.rows>
        @forelse ($sessions as $session)
            <flux:table.row :key="$session->id">
                <flux:table.cell class="whitespace-nowrap">{{ $session->started_at->toDayDateTimeString() }}</flux:table.cell>
                @if ($showTenant)
                    <flux:table.cell>
                        <flux:link :href="route('platform.tenants.show', $session->tenant_id)">{{ $session->tenant?->name ?? $session->tenant_id }}</flux:link>
                    </flux:table.cell>
                @endif
                <flux:table.cell>{{ $session->actor_email }}</flux:table.cell>
                <flux:table.cell>{{ $session->target_email }}</flux:table.cell>
                <flux:table.cell>
                    <flux:badge size="sm" inset="top bottom" :color="$session->status()->color()">{{ $session->status()->label() }}</flux:badge>
                    @if ($session->end_reason)
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $session->end_reason->label() }}</div>
                    @endif
                </flux:table.cell>
                <flux:table.cell class="max-w-xs whitespace-normal">{{ \Illuminate\Support\Str::limit($session->reason, 120) }}</flux:table.cell>
                <flux:table.cell align="end">
                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" variant="ghost" :href="route('platform.support-sessions.show', $session)">{{ __('Details') }}</flux:button>
                        @if ($session->isRevocable())
                            <form method="POST" action="{{ route('platform.support-sessions.revoke', $session) }}">
                                @csrf
                                <flux:button size="sm" variant="danger" type="submit" data-test="revoke-support-session">{{ __('Revoke') }}</flux:button>
                            </form>
                        @endif
                    </div>
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell :colspan="$showTenant ? 7 : 6">{{ __('No support sessions yet.') }}</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
