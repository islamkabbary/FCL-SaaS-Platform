<x-layouts::platform :title="__('Support session · :name', ['name' => $tenant->name])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.tenants.index')">{{ __('Tenants') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('platform.tenants.show', $tenant)">{{ $tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Start support session') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Start a support session') }}</flux:heading>
        <flux:subheading>{{ $tenant->name }} · {{ $tenant->url() }}</flux:subheading>

        @if (session('error'))
            <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="session('error')" />
        @endif

        @if ($ineligibility !== null)
            <flux:callout variant="danger" icon="no-symbol" class="mt-6" :heading="__('Support access is not possible')" data-test="support-ineligible">
                <flux:callout.text>{{ $ineligibility }}</flux:callout.text>
            </flux:callout>
        @elseif ($requiresTwoFactor && ! $hasTwoFactor)
            <flux:callout variant="warning" icon="shield-exclamation" class="mt-6" :heading="__('Two-factor authentication required')" data-test="support-requires-two-factor">
                <flux:callout.text>{{ __('Set up an authenticator app for your platform account before starting support sessions.') }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('platform.account.security')">{{ __('Account security') }}</flux:button>
                </x-slot>
            </flux:callout>
        @elseif ($targets->isEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('This company has no active Tenant Admin to act as.')" />
        @else
            <flux:callout icon="information-circle" class="mt-6" :heading="__('Restricted, temporary and recorded')">
                <flux:callout.text>{{ __('You will see the application as the selected Tenant Admin for at most :minutes minutes. Changes (passwords, roles, employees, billing and every other modification) are refused.', ['minutes' => $lifetime]) }}</flux:callout.text>
                <flux:callout.text>{{ __('The company\'s Tenant Admins are notified with your name and the reason, and the pages you open are recorded.') }}</flux:callout.text>
            </flux:callout>

            <form method="POST" action="{{ route('platform.support-sessions.store', $tenant) }}" class="mt-6 flex flex-col gap-6">
                @csrf

                <flux:radio.group name="target_user_id" :label="__('Account to access')" required>
                    @foreach ($targets as $target)
                        <flux:radio :value="$target['id']" :label="$target['name'].' <'.$target['email'].'>'" :checked="(string) old('target_user_id', $loop->first ? $target['id'] : '') === (string) $target['id']" />
                    @endforeach
                </flux:radio.group>
                @error('target_user_id') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

                <flux:textarea
                    name="reason"
                    :label="__('Reason or support reference')"
                    :description="__('Required. Shown to the company\'s Tenant Admins and recorded in the audit trail.')"
                    rows="3"
                    required
                >{{ old('reason') }}</flux:textarea>

                <flux:input name="password" type="password" :label="__('Your password')" :description="__('Confirms it is you, for this request.')" required autocomplete="current-password" />

                @if ($requiresTwoFactor)
                    <flux:input name="code" :label="__('Authenticator code')" inputmode="numeric" autocomplete="one-time-code" required />
                @endif

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" data-test="start-support-button">{{ __('Start support session') }}</flux:button>
                    <flux:link :href="route('platform.tenants.show', $tenant)">{{ __('Cancel') }}</flux:link>
                </div>
            </form>
        @endif
    </div>
</x-layouts::platform>
