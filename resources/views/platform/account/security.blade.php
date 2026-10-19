<x-layouts::platform :title="__('Account security')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Account security') }}</flux:heading>
        <flux:subheading>{{ $admin->email }}</flux:subheading>

        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
        @endif

        <flux:heading size="lg" level="2" class="mt-8">{{ __('Two-factor authentication') }}</flux:heading>

        @if ($admin->hasTwoFactorEnabled())
            <flux:callout variant="success" icon="shield-check" class="mt-4" :heading="__('Enabled since :date. Every login asks for a code from your authenticator app.', ['date' => $admin->two_factor_confirmed_at?->toDayDateTimeString()])" data-test="two-factor-enabled" />
            <flux:text class="mt-2">{{ __(':count unused recovery codes left.', ['count' => count($admin->two_factor_recovery_codes ?? [])]) }}</flux:text>

            <form method="POST" action="{{ route('platform.account.two-factor.recovery-codes') }}" class="mt-6 flex flex-col gap-4">
                @csrf
                <flux:heading>{{ __('New recovery codes') }}</flux:heading>
                <flux:input name="password" type="password" :label="__('Your password')" required autocomplete="current-password" />
                <flux:input name="code" :label="__('Authenticator code')" inputmode="numeric" autocomplete="one-time-code" required />
                <div><flux:button type="submit">{{ __('Regenerate recovery codes') }}</flux:button></div>
            </form>

            <form method="POST" action="{{ route('platform.account.two-factor.disable') }}" class="mt-8 flex flex-col gap-4">
                @csrf
                @method('DELETE')
                <flux:heading>{{ __('Disable two-factor authentication') }}</flux:heading>
                <flux:input name="password" type="password" :label="__('Your password')" required autocomplete="current-password" />
                <flux:input name="code" :label="__('Authenticator code')" inputmode="numeric" autocomplete="one-time-code" />
                <flux:input name="recovery_code" :label="__('Or a recovery code')" autocomplete="off" />
                <div><flux:button variant="danger" type="submit">{{ __('Disable') }}</flux:button></div>
            </form>
        @elseif ($admin->hasPendingTwoFactor())
            <flux:text class="mt-4">{{ __('Scan this QR code with your authenticator app, or enter the setup key, then confirm with the code it shows.') }}</flux:text>

            <div class="mt-4 inline-block rounded-lg bg-white p-3" data-test="two-factor-qr">{!! $qrCode !!}</div>
            <flux:input class="mt-4" :label="__('Setup key')" :value="$setupKey" readonly copyable />

            <form method="POST" action="{{ route('platform.account.two-factor.confirm') }}" class="mt-6 flex flex-col gap-4">
                @csrf
                <flux:input name="code" :label="__('Authenticator code')" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                <div><flux:button variant="primary" type="submit" data-test="confirm-two-factor-button">{{ __('Confirm') }}</flux:button></div>
            </form>
        @else
            <flux:callout variant="warning" icon="shield-exclamation" class="mt-4" :heading="__('Not enabled. Platform Admin accounts are highly privileged; an authenticator app is strongly recommended, and is required to start support sessions.')" data-test="two-factor-disabled" />

            <form method="POST" action="{{ route('platform.account.two-factor.enable') }}" class="mt-6 flex flex-col gap-4">
                @csrf
                <flux:input name="password" type="password" :label="__('Your password')" required autocomplete="current-password" />
                <div><flux:button variant="primary" type="submit" data-test="enable-two-factor-button">{{ __('Set up two-factor authentication') }}</flux:button></div>
            </form>
        @endif
    </div>
</x-layouts::platform>
