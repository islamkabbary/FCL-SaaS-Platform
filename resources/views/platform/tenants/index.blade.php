<x-layouts::platform :title="__('Tenants')">
    <div class="flex items-center justify-between">
        <flux:heading size="xl" level="1">{{ __('Tenants') }}</flux:heading>

        <flux:button variant="primary" icon="plus" :href="route('platform.tenants.create')" data-test="create-tenant-link">
            {{ __('New tenant') }}
        </flux:button>
    </div>

    @if (session('error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="session('error')" />
    @endif

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Company') }}</flux:table.column>
            <flux:table.column>{{ __('Subdomain') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Created') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($tenants as $tenant)
                <flux:table.row :key="$tenant->id">
                    <flux:table.cell variant="strong">{{ $tenant->name }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($tenant->isReady())
                            <flux:link :href="$tenant->url('login')" external>{{ $tenant->primaryDomain()?->domain }}</flux:link>
                        @else
                            {{ $tenant->primaryDomain()?->domain }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="match ($tenant->status) {
                            \App\Enums\TenantStatus::Ready => 'green',
                            \App\Enums\TenantStatus::Failed => 'red',
                            default => 'zinc',
                        }">{{ $tenant->status->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $tenant->created_at?->toDayDateTimeString() }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">{{ __('No tenants yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</x-layouts::platform>
