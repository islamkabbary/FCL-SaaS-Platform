<x-layouts::platform :title="__('Suspend :name', ['name' => $tenant->name])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.tenants.index')">{{ __('Tenants') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('platform.tenants.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Suspend access') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Suspend :name?', ['name' => $tenant->name]) }}</flux:heading>

        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" :heading="__('Every user of this company loses access immediately.')">
            <flux:callout.text>{{ __('Signed-in users are blocked on their next request, and nobody can sign in at :url until the tenant is reactivated.', ['url' => $tenant->url()]) }}</flux:callout.text>
            <flux:callout.text>{{ __('Nothing is deleted: users, roles, sessions, the tenant database and its files are kept, and reactivating restores access as it was.') }}</flux:callout.text>
        </flux:callout>

        <form method="POST" action="{{ route('platform.tenants.suspend.store', $tenant) }}" class="mt-6 flex flex-col gap-6">
            @csrf

            <flux:textarea
                name="reason"
                :label="__('Reason')"
                :description="__('Required. Recorded in the tenant\'s history; it is not shown to the tenant\'s users.')"
                rows="3"
                required
                autofocus
            >{{ old('reason') }}</flux:textarea>

            <div class="flex items-center gap-4">
                <flux:button variant="danger" type="submit" data-test="confirm-suspend-button">{{ __('Suspend tenant') }}</flux:button>
                <flux:link :href="route('platform.tenants.show', $tenant)">{{ __('Cancel') }}</flux:link>
            </div>
        </form>
    </div>
</x-layouts::platform>
