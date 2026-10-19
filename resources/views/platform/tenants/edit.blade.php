<x-layouts::platform :title="__('Edit :name', ['name' => $tenant->name])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.tenants.index')">{{ __('Tenants') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('platform.tenants.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Edit') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Edit tenant') }}</flux:heading>
        <flux:subheading>{{ __('Administrative details kept in the central database. Changes are recorded in the tenant\'s history.') }}</flux:subheading>

        <form method="POST" action="{{ route('platform.tenants.update', $tenant) }}" class="mt-6 flex flex-col gap-6">
            @csrf
            @method('PUT')

            <flux:input name="name" :label="__('Company name')" :value="old('name', $tenant->name)" required autofocus />

            <flux:input
                :label="__('Subdomain')"
                :value="$tenant->primaryDomain()?->domain"
                :description="__('The subdomain identifies the tenant and its database, and cannot be changed.')"
                disabled
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-tenant-button">{{ __('Save') }}</flux:button>
                <flux:link :href="route('platform.tenants.show', $tenant)">{{ __('Cancel') }}</flux:link>
            </div>
        </form>
    </div>
</x-layouts::platform>
