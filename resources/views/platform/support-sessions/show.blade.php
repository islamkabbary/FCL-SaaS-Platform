<x-layouts::platform :title="__('Support session #:id', ['id' => $session->id])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.support-sessions.index')">{{ __('Support sessions') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('#:id', ['id' => $session->id]) }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <flux:heading size="xl" level="1">{{ $session->tenant?->name }}</flux:heading>
        <flux:badge :color="$session->status()->color()" data-test="support-session-status">{{ $session->status()->label() }}</flux:badge>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    @if (session('error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="session('error')" />
    @endif

    <flux:card class="mt-6 max-w-3xl">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Platform Admin') }}</dt>
            <dd class="sm:col-span-2">{{ $session->actor_name }} &lt;{{ $session->actor_email }}&gt;</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Acting as') }}</dt>
            <dd class="sm:col-span-2">{{ $session->target_name }} &lt;{{ $session->target_email }}&gt;</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Reason') }}</dt>
            <dd class="whitespace-pre-line sm:col-span-2">{{ $session->reason }}</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Started') }}</dt>
            <dd class="sm:col-span-2">{{ $session->started_at->toDayDateTimeString() }}</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Entered the company') }}</dt>
            <dd class="sm:col-span-2">{{ $session->handoff_consumed_at?->toDayDateTimeString() ?? __('Not entered') }}</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Expires') }}</dt>
            <dd class="sm:col-span-2">{{ $session->expires_at->toDayDateTimeString() }}</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Ended') }}</dt>
            <dd class="sm:col-span-2">
                @if ($session->ended_at)
                    {{ $session->ended_at->toDayDateTimeString() }} · {{ $session->end_reason?->label() }}
                    @if ($session->ended_by_email) · {{ $session->ended_by_email }} @endif
                @else
                    —
                @endif
            </dd>
        </dl>

        @if ($session->isRevocable())
            <form method="POST" action="{{ route('platform.support-sessions.revoke', $session) }}" class="mt-6">
                @csrf
                <flux:button variant="danger" type="submit" data-test="revoke-support-session">{{ __('Revoke now') }}</flux:button>
            </form>
        @endif
    </flux:card>

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Activity during the session') }}</flux:heading>
    <flux:table class="mt-4" data-test="support-session-events">
        <flux:table.columns>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Page or operation') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($events as $event)
                <flux:table.row :key="$event->id">
                    <flux:table.cell class="whitespace-nowrap">{{ $event->created_at?->toDayDateTimeString() }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" inset="top bottom" :color="$event->type === 'denied' ? 'red' : 'zinc'">{{ $event->type === 'denied' ? __('Refused') : __('Viewed') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">{{ $event->http_method }} {{ $event->action }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3">{{ __('No activity recorded.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</x-layouts::platform>
