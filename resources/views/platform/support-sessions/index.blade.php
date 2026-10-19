<x-layouts::platform :title="__('Support sessions')">
    <flux:heading size="xl" level="1">{{ __('Support sessions') }}</flux:heading>
    <flux:subheading>{{ __('Temporary, restricted access of Platform Admins to companies.') }}</flux:subheading>

    @unless (config('impersonation.support.enabled'))
        <flux:callout icon="lock-closed" class="mt-6" :heading="__('Support access is disabled in this environment (SUPPORT_ACCESS_ENABLED).')" data-test="support-disabled" />
    @endunless

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    @if (session('error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="session('error')" />
    @endif

    <form method="GET" class="mt-6 flex gap-3">
        <flux:select name="status" class="max-w-48" onchange="this.form.submit()">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (\App\Enums\ImpersonationStatus::cases() as $option)
                <flux:select.option :value="$option->value" :selected="$status === $option">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </form>

    @include('platform.support-sessions.partials.table', ['sessions' => $sessions, 'showTenant' => true])
</x-layouts::platform>
