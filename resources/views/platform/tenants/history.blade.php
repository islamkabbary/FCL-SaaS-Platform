<x-layouts::platform :title="__(':name history', ['name' => $tenant->name])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.tenants.index')">{{ __('Tenants') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('platform.tenants.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('History') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1" class="mt-4">{{ __('Administration history') }}</flux:heading>
    <flux:subheading>{{ __('Actions Platform Admins performed on :name, newest first. Records are read-only.', ['name' => $tenant->name]) }}</flux:subheading>

    <div class="mt-6">
        @include('platform.tenants.partials.audit-entries', ['entries' => $entries])
    </div>

    <div class="mt-6">
        {{ $entries->links() }}
    </div>
</x-layouts::platform>
