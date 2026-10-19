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

    <form method="GET" action="{{ route('platform.tenants.index') }}" class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-end" data-test="tenant-filters">
        <div class="sm:flex-1">
            <flux:input name="search" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Company name or subdomain')" :value="$filters['search']" />
        </div>

        <flux:select name="status" :label="__('Provisioning')" class="sm:max-w-44">
            <flux:select.option value="">{{ __('Any') }}</flux:select.option>
            @foreach (\App\Enums\TenantStatus::cases() as $status)
                <flux:select.option :value="$status->value" :selected="$filters['status'] === $status">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select name="access" :label="__('Access')" class="sm:max-w-44">
            <flux:select.option value="">{{ __('Any') }}</flux:select.option>
            @foreach (\App\Enums\TenantAccessStatus::cases() as $access)
                <flux:select.option :value="$access->value" :selected="$filters['access'] === $access">{{ $access->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex gap-2">
            <flux:button type="submit" variant="filled">{{ __('Filter') }}</flux:button>
            @if ($filters['search'] !== '' || $filters['status'] || $filters['access'])
                <flux:button variant="ghost" :href="route('platform.tenants.index')">{{ __('Reset') }}</flux:button>
            @endif
        </div>
    </form>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Company') }}</flux:table.column>
            <flux:table.column>{{ __('Subdomain') }}</flux:table.column>
            <flux:table.column>{{ __('Provisioning') }}</flux:table.column>
            <flux:table.column>{{ __('Access') }}</flux:table.column>
            <flux:table.column>{{ __('Created') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($tenants as $tenant)
                <flux:table.row :key="$tenant->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('platform.tenants.show', $tenant)" variant="ghost">{{ $tenant->name }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($tenant->isAccessible())
                            <flux:link :href="$tenant->url('login')" external>{{ $tenant->primaryDomain()?->domain }}</flux:link>
                        @else
                            {{ $tenant->primaryDomain()?->domain }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="$tenant->status->color()">{{ $tenant->status->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="$tenant->accessStatus()->color()">{{ $tenant->accessStatus()->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $tenant->created_at?->toDayDateTimeString() }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon-trailing="chevron-right" inset="top bottom" :href="route('platform.tenants.show', $tenant)">
                            {{ __('Details') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">
                        {{ $filters['search'] !== '' || $filters['status'] || $filters['access'] ? __('No tenants match these filters.') : __('No tenants yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-6">
        {{ $tenants->links() }}
    </div>
</x-layouts::platform>
