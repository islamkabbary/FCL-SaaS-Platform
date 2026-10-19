<x-layouts::platform :title="__(':name subscription', ['name' => $tenant->name])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.subscriptions.index')">{{ __('Subscriptions') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('platform.tenants.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Subscription') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $tenant->name }}</flux:heading>
            <div class="mt-2 flex flex-wrap gap-2">
                <flux:badge size="sm" :color="$status->color()" data-test="subscription-status">{{ __('Subscription') }}: {{ $status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$tenant->status->color()">{{ __('Provisioning') }}: {{ $tenant->status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$tenant->accessStatus()->color()">{{ __('Access') }}: {{ $tenant->accessStatus()->label() }}</flux:badge>
            </div>
        </div>

        <div class="flex gap-2">
            @if ($subscription === null)
                <flux:button variant="primary" icon="play" :href="route('platform.subscriptions.activate', $tenant)" data-test="activate-subscription-link">{{ __('Activate subscription') }}</flux:button>
            @else
                <flux:button variant="primary" icon="arrow-path" :href="route('platform.subscriptions.renew', $tenant)" data-test="renew-subscription-link">{{ __('Renewal') }}</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif
    @if (session('error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="session('error')" />
    @endif

    @if ($status === App\Enums\SubscriptionStatus::Legacy)
        <flux:callout icon="information-circle" class="mt-6" :heading="__('Legacy tenant: created before subscriptions existed. It keeps full access with no seat limit until a subscription is activated.')" />
    @elseif ($status === App\Enums\SubscriptionStatus::None)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('No subscription yet. Users can sign in but cannot use the application, and no employees can be added, until a subscription is activated.')" />
    @elseif ($status === App\Enums\SubscriptionStatus::Expired)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" :heading="__('Expired. Users can sign in but cannot use the application until the subscription is renewed.')" />
    @endif

    @if ($tenant->isSuspended())
        <flux:callout variant="danger" icon="no-symbol" class="mt-4" :heading="__('This tenant is also manually suspended. The subscription does not lift the suspension.')" />
    @endif

    <div class="mt-8 grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text>{{ __('Purchased seats') }}</flux:text>
            <flux:heading size="xl" class="mt-2 tabular-nums" data-test="purchased-seats">{{ $seatLimit === null ? __('Unlimited') : $seatLimit }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Active users') }}</flux:text>
            <flux:heading size="xl" class="mt-2 tabular-nums" data-test="active-users">{{ $activeUsers ?? '—' }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Outstanding') }}</flux:text>
            @php($outstanding = $charges->sum(fn ($charge) => $charge->balance()))
            <flux:heading size="xl" class="mt-2 tabular-nums" data-test="outstanding">{{ $subscription ? App\Billing\Money::format($outstanding, $subscription->currency) : '—' }}</flux:heading>
        </flux:card>
    </div>

    @if ($currentTerm)
        <flux:card class="mt-6">
            <flux:heading size="lg" level="2">{{ __('Current term') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Plan') }}</dt>
                <dd class="sm:col-span-3">{{ $currentTerm->plan_name }} ({{ $currentTerm->currency }})</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Effective') }}</dt>
                <dd class="sm:col-span-3">{{ __(':start to :end (inclusive)', ['start' => $currentTerm->starts_on->toFormattedDateString(), 'end' => $currentTerm->lastDay()->toFormattedDateString()]) }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Next renewal') }}</dt>
                <dd class="sm:col-span-3" data-test="renewal-date">{{ $currentTerm->ends_on->toFormattedDateString() }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Prices (snapshot)') }}</dt>
                <dd class="sm:col-span-3">{{ $currentTerm->format($currentTerm->annual_base_fee) }} + {{ __('seat') }} × {{ $currentTerm->format($currentTerm->annual_seat_price) }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Seats') }}</dt>
                <dd class="sm:col-span-3">{{ __(':seats (started with :initial)', ['seats' => $currentTerm->seats, 'initial' => $currentTerm->initial_seats]) }}</dd>
            </dl>
            @if ($scheduledReduction)
                <flux:callout icon="calendar" class="mt-4" :heading="__('Approved reduction to :seats seats at the next renewal (request #:id).', ['seats' => $scheduledReduction->seats, 'id' => $scheduledReduction->id])" />
            @endif
        </flux:card>
    @endif

    @if ($terms->isNotEmpty())
        <flux:heading size="lg" level="2" class="mt-10">{{ __('Term history') }}</flux:heading>
        <flux:table class="mt-4" data-test="term-history">
            <flux:table.columns>
                <flux:table.column>#</flux:table.column>
                <flux:table.column>{{ __('Period') }}</flux:table.column>
                <flux:table.column>{{ __('Kind') }}</flux:table.column>
                <flux:table.column>{{ __('Plan') }}</flux:table.column>
                <flux:table.column>{{ __('Seats') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Base fee') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Seat price') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($terms as $term)
                    <flux:table.row :key="$term->id">
                        <flux:table.cell>{{ $term->sequence }}</flux:table.cell>
                        <flux:table.cell>{{ $term->starts_on->toDateString() }} – {{ $term->lastDay()->toDateString() }}</flux:table.cell>
                        <flux:table.cell>{{ $term->kind->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $term->plan_name }}</flux:table.cell>
                        <flux:table.cell>{{ $term->seats }} ({{ $term->initial_seats }})</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $term->format($term->annual_base_fee) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $term->format($term->annual_seat_price) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Charges') }}</flux:heading>
    @include('platform.charges.partials.table', ['charges' => $charges, 'showTenant' => false])

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Seat requests') }}</flux:heading>
    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>#</flux:table.column>
            <flux:table.column>{{ __('Requested') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Seats') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($requests as $request)
                <flux:table.row :key="$request->id">
                    <flux:table.cell>{{ $request->id }}</flux:table.cell>
                    <flux:table.cell>{{ $request->created_at?->toFormattedDateString() }} · {{ $request->requested_by_email }}</flux:table.cell>
                    <flux:table.cell>{{ $request->type->label() }}</flux:table.cell>
                    <flux:table.cell>{{ $request->seats }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" inset="top bottom" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge></flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">{{ __('No seat requests.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</x-layouts::platform>
