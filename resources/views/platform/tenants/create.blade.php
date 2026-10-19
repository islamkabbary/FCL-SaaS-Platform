<x-layouts::platform :title="__('New tenant')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('New tenant') }}</flux:heading>
        <flux:subheading>
            {{ __('Creates the company, its own database, its Default Branch and its first Tenant Admin.') }}
        </flux:subheading>

        <form method="POST" action="{{ route('platform.tenants.store') }}" class="mt-6 flex flex-col gap-6">
            @csrf

            <flux:input name="company_name" :label="__('Company name')" :value="old('company_name')" required autofocus />

            <flux:input
                name="subdomain"
                :label="__('Subdomain')"
                :value="old('subdomain')"
                :description="__('Lowercase letters, numbers and hyphens. The company will be reachable at {subdomain}.:domain', ['domain' => config('tenancy.central_domains')[0]])"
                required
            />

            <flux:separator variant="subtle" />

            <flux:input name="admin_name" :label="__('Tenant Admin name')" :value="old('admin_name')" required />

            <flux:input name="admin_email" type="email" :label="__('Tenant Admin email')" :value="old('admin_email')" required />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="create-tenant-button">
                    {{ __('Create tenant') }}
                </flux:button>

                <flux:link :href="route('platform.tenants.index')">{{ __('Cancel') }}</flux:link>
            </div>
        </form>
    </div>
</x-layouts::platform>
