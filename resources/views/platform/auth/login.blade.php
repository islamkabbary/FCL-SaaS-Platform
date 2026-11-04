<x-layouts::auth :title="__('Platform admin log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Platform administration')" :description="__('Log in with your platform admin account')" />

        <form method="POST" action="{{ route('platform.login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="login"
                :label="__('Username or email')"
                :value="old('login')"
                type="text" dir="ltr"
                required
                autofocus
                autocomplete="username"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="platform-login-button">
                {{ __('Log in') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
