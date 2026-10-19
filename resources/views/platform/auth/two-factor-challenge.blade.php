<x-layouts::auth :title="__('Two-factor authentication')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Two-factor authentication')" :description="__('Enter the code from your authenticator app, or one of your recovery codes.')" />

        <form method="POST" action="{{ route('platform.two-factor.challenge.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input name="code" :label="__('Authentication code')" inputmode="numeric" autocomplete="one-time-code" autofocus />

            <flux:input name="recovery_code" :label="__('Or a recovery code')" autocomplete="off" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="two-factor-challenge-button">
                {{ __('Continue') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
