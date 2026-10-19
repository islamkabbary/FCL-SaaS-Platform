<x-layouts::app :title="__('Dashboard')">
    @can('view-access-history')
        @php($supportNotices = auth()->user()->unreadNotifications()->where('type', App\Notifications\SupportAccessStarted::class)->count())
        @if ($supportNotices > 0)
            <flux:callout variant="warning" icon="lifebuoy" class="mb-4" data-test="dashboard-support-notice"
                :heading="__('Platform support accessed your company. Review the access history.')">
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('access-history.index')" wire:navigate>{{ __('Access history') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @endcan

    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
        </div>
        <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
        </div>
    </div>
</x-layouts::app>
