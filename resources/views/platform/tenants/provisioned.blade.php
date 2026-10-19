<x-layouts::platform :title="__('Tenant provisioned')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('":name" is ready', ['name' => $result->tenant->name]) }}</flux:heading>

        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6"
            :heading="__('This temporary password is shown only once.')"
            :text="__('Copy it now and hand it to the Tenant Admin through a secure channel. It is not stored anywhere in plain text and cannot be displayed again. The Tenant Admin must change it at first login.')"
        />

        <flux:card class="mt-6 space-y-4">
            <div>
                <flux:label>{{ __('Login URL') }}</flux:label>
                <flux:text><flux:link :href="$loginUrl" external>{{ $loginUrl }}</flux:link></flux:text>
            </div>

            <div>
                <flux:label>{{ __('Tenant Admin email') }}</flux:label>
                <flux:text>{{ $result->adminEmail }}</flux:text>
            </div>

            <flux:input :label="__('Temporary password')" :value="$result->temporaryPassword" readonly copyable data-test="temporary-password" />
        </flux:card>

        <div class="mt-6">
            <flux:button :href="route('platform.tenants.index')">{{ __('Back to tenants') }}</flux:button>
        </div>
    </div>
</x-layouts::platform>
