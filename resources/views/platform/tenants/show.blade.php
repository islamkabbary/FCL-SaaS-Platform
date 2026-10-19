<x-layouts::platform :title="$tenant->name">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.tenants.index')">{{ __('Tenants') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $tenant->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $tenant->name }}</flux:heading>
            <div class="mt-2 flex flex-wrap gap-2">
                <flux:badge size="sm" :color="$tenant->status->color()" data-test="provisioning-status">{{ __('Provisioning') }}: {{ $tenant->status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$tenant->accessStatus()->color()" data-test="access-status">{{ __('Access') }}: {{ $tenant->accessStatus()->label() }}</flux:badge>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="pencil-square" :href="route('platform.tenants.edit', $tenant)" data-test="edit-tenant-link">{{ __('Edit') }}</flux:button>
            <flux:button icon="identification" :href="route('platform.subscriptions.show', $tenant)" data-test="subscription-link">{{ __('Subscription') }}: {{ $tenant->subscriptionStatus()->label() }}</flux:button>

            @can('start-support-session')
                @if ($tenant->isAccessible())
                    <flux:button icon="lifebuoy" :href="route('platform.support-sessions.create', $tenant)" data-test="start-support-link">{{ __('Start support session') }}</flux:button>
                @endif
            @endcan

            @if ($tenant->canBeSuspended())
                <flux:button variant="danger" icon="pause-circle" :href="route('platform.tenants.suspend', $tenant)" data-test="suspend-tenant-link">{{ __('Suspend access') }}</flux:button>
            @elseif ($tenant->canBeReactivated())
                <flux:button variant="primary" icon="play-circle" :href="route('platform.tenants.reactivate', $tenant)" data-test="reactivate-tenant-link">{{ __('Reactivate access') }}</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    @if (session('error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="session('error')" />
    @endif

    @if ($tenant->isSuspended())
        <flux:callout variant="danger" icon="no-symbol" class="mt-6" :heading="__('Access suspended on :date', ['date' => $tenant->suspended_at?->toDayDateTimeString()])" data-test="suspension-callout">
            <flux:callout.text>{{ __('Reason') }}: {{ $tenant->suspension_reason }}</flux:callout.text>
            <flux:callout.text>{{ __('Users of this company cannot sign in or use the application. Their data, accounts and sessions are kept.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($tenant->status === \App\Enums\TenantStatus::Failed)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('Provisioning failed')" data-test="provisioning-failure">
            @if ($tenant->provisioningFailureSummary())
                <flux:callout.text>{{ $tenant->provisioningFailureSummary() }}</flux:callout.text>
            @endif
            <flux:callout.text>
                {{ __('Fix the cause, then resume provisioning from the command line:') }}
                <code class="font-mono text-sm">php artisan tenants:provision {{ $tenant->primaryDomain()?->domain ?? $tenant->id }}</code>
            </flux:callout.text>
        </flux:callout>
    @elseif (! $tenant->isReady())
        <flux:callout icon="clock" class="mt-6" :heading="__('This tenant has not finished provisioning and is not reachable yet.')" />
    @endif

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Company') }}</flux:heading>

            <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Company name') }}</dt>
                <dd class="sm:col-span-2" data-test="tenant-name">{{ $tenant->name }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Subdomain') }}</dt>
                <dd class="sm:col-span-2">
                    @if ($tenant->isAccessible())
                        <flux:link :href="$tenant->url('login')" external>{{ $tenant->url() }}</flux:link>
                    @else
                        {{ $tenant->url() }}
                    @endif
                </dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Initial Tenant Admin') }}</dt>
                <dd class="sm:col-span-2">{{ $tenant->initial_admin_name ?? '—' }} @if ($tenant->initial_admin_email) &lt;{{ $tenant->initial_admin_email }}&gt; @endif</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Created') }}</dt>
                <dd class="sm:col-span-2">{{ $tenant->created_at?->toDayDateTimeString() }}</dd>
            </dl>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" level="2">{{ __('Platform diagnostics') }}</flux:heading>

            <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Tenant ID') }}</dt>
                <dd class="font-mono break-all sm:col-span-2" data-test="tenant-id">{{ $tenant->id }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Database') }}</dt>
                <dd class="font-mono break-all sm:col-span-2" data-test="tenant-database">{{ $databaseName }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Provisioning') }}</dt>
                <dd class="sm:col-span-2">{{ $tenant->status->label() }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Provisioned') }}</dt>
                <dd class="sm:col-span-2">{{ $tenant->provisioned_at?->toDayDateTimeString() ?? '—' }}</dd>

                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Access') }}</dt>
                <dd class="sm:col-span-2">
                    {{ $tenant->accessStatus()->label() }}
                    · {{ $tenant->isAccessible() ? __('users can sign in') : __('users cannot sign in') }}
                </dd>
            </dl>
        </flux:card>
    </div>

    <div class="mt-10 flex items-center justify-between">
        <flux:heading size="lg" level="2">{{ __('Support sessions') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('platform.support-sessions.index', ['tenant' => $tenant->id])">{{ __('All support sessions') }}</flux:button>
    </div>

    @include('platform.support-sessions.partials.table', ['sessions' => $supportSessions])

    <div class="mt-10 flex items-center justify-between">
        <flux:heading size="lg" level="2">{{ __('Administration history') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('platform.tenants.history', $tenant)" data-test="history-link">
            {{ __('Full history (:count)', ['count' => $activityCount]) }}
        </flux:button>
    </div>

    <div class="mt-4">
        @include('platform.tenants.partials.audit-entries', ['entries' => $recentActivity])
    </div>
</x-layouts::platform>
