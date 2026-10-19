<x-layouts::auth :title="__('Platform admin log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Platform administration')" :description="__('Log in with your platform admin account')" />

        <form method="POST" action="{{ route('platform.login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
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

            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="platform-login-button">
                {{ __('Log in') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
