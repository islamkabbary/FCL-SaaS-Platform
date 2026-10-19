<x-layouts::platform :title="__('Dashboard')">
    <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
    <flux:subheading>{{ __('Tenant companies registered on the platform.') }}</flux:subheading>

    @php
        $cards = [
            ['key' => 'total', 'label' => __('Total tenants'), 'query' => []],
            ['key' => 'active', 'label' => __('Ready & active'), 'query' => ['status' => 'ready', 'access' => 'active']],
            ['key' => 'suspended', 'label' => __('Suspended'), 'query' => ['access' => 'suspended']],
            ['key' => 'pending', 'label' => __('Pending'), 'query' => ['status' => 'pending']],
            ['key' => 'provisioning', 'label' => __('Provisioning'), 'query' => ['status' => 'provisioning']],
            ['key' => 'failed', 'label' => __('Failed provisioning'), 'query' => ['status' => 'failed']],
        ];
    @endphp

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        @foreach ($cards as $card)
            <a href="{{ route('platform.tenants.index', $card['query']) }}" class="block" data-test="stat-{{ $card['key'] }}">
                <flux:card class="h-full transition hover:border-zinc-300 dark:hover:border-zinc-500">
                    <flux:text>{{ $card['label'] }}</flux:text>
                    <flux:heading size="xl" class="mt-2 tabular-nums">{{ $stats[$card['key']] }}</flux:heading>
                </flux:card>
            </a>
        @endforeach
    </div>

    <flux:text class="mt-3 text-xs">
        {{ __('Pending, provisioning and failed tenants are not reachable yet. A suspended tenant has finished provisioning, but its users cannot access the application.') }}
    </flux:text>

    <div class="mt-10 flex items-center justify-between">
        <flux:heading size="lg" level="2">{{ __('Recent administration activity') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('platform.tenants.index')">
            {{ __('All tenants') }}
        </flux:button>
    </div>

    <div class="mt-4">
        @include('platform.tenants.partials.audit-entries', ['entries' => $recentActivity, 'showTenant' => true])
    </div>
</x-layouts::platform>
