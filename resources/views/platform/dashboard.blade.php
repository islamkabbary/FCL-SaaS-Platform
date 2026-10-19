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

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Subscriptions & billing') }}</flux:heading>

    @php
        $billingCards = [
            ['key' => 'active', 'label' => __('Active subscriptions'), 'href' => route('platform.subscriptions.index', ['status' => 'active'])],
            ['key' => 'renewal_due', 'label' => __('Renewals due (:days days)', ['days' => config('billing.renewal_window_days')]), 'href' => route('platform.subscriptions.index', ['status' => 'renewal_due'])],
            ['key' => 'expired', 'label' => __('Expired subscriptions'), 'href' => route('platform.subscriptions.index', ['status' => 'expired'])],
            ['key' => 'pending_requests', 'label' => __('Pending seat requests'), 'href' => route('platform.seat-requests.index')],
        ];
    @endphp

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($billingCards as $card)
            <a href="{{ $card['href'] }}" class="block" data-test="billing-stat-{{ $card['key'] }}">
                <flux:card class="h-full transition hover:border-zinc-300 dark:hover:border-zinc-500">
                    <flux:text>{{ $card['label'] }}</flux:text>
                    <flux:heading size="xl" class="mt-2 tabular-nums">{{ $billing[$card['key']] }}</flux:heading>
                </flux:card>
            </a>
        @endforeach

        <a href="{{ route('platform.charges.index', ['outstanding' => 1]) }}" class="block" data-test="billing-stat-outstanding">
            <flux:card class="h-full transition hover:border-zinc-300 dark:hover:border-zinc-500">
                <flux:text>{{ __('Outstanding') }}</flux:text>
                @forelse ($billing['outstanding'] as $currency => $balance)
                    <flux:heading size="lg" class="mt-2 tabular-nums">{{ App\Billing\Money::format($balance, $currency) }}</flux:heading>
                @empty
                    <flux:heading size="xl" class="mt-2 tabular-nums">0</flux:heading>
                @endforelse
            </flux:card>
        </a>
    </div>

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
