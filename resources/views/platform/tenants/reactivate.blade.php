<x-layouts::platform :title="__('Reactivate :name', ['name' => $tenant->name])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.tenants.index')">{{ __('Tenants') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('platform.tenants.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Reactivate access') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Reactivate :name?', ['name' => $tenant->name]) }}</flux:heading>

        <flux:callout icon="information-circle" class="mt-6" :heading="__('Suspended on :date', ['date' => $tenant->suspended_at?->toDayDateTimeString()])">
            <flux:callout.text>{{ __('Reason') }}: {{ $tenant->suspension_reason }}</flux:callout.text>
            <flux:callout.text>{{ __('The company\'s users regain access immediately with their existing accounts, roles and data. Nothing is re-provisioned.') }}</flux:callout.text>
        </flux:callout>

        <form method="POST" action="{{ route('platform.tenants.reactivate.store', $tenant) }}" class="mt-6 flex flex-col gap-6">
            @csrf

            <flux:textarea
                name="reason"
                :label="__('Note')"
                :description="__('Optional. Recorded in the tenant\'s history.')"
                rows="3"
            >{{ old('reason') }}</flux:textarea>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="confirm-reactivate-button">{{ __('Reactivate tenant') }}</flux:button>
                <flux:link :href="route('platform.tenants.show', $tenant)">{{ __('Cancel') }}</flux:link>
            </div>
        </form>
    </div>
</x-layouts::platform>
