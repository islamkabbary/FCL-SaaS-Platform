<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:brand :href="route('platform.dashboard')" :name="config('app.name').' · '.__('Platform')" class="me-6" />

            <flux:navbar class="-mb-px">
                <flux:navbar.item icon="home" :href="route('platform.dashboard')" :current="request()->routeIs('platform.dashboard')">
                    {{ __('Dashboard') }}
                </flux:navbar.item>
                <flux:navbar.item icon="building-office-2" :href="route('platform.tenants.index')" :current="request()->routeIs('platform.tenants.*')">
                    {{ __('Tenants') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <flux:text class="me-4 hidden sm:block">{{ auth('platform')->user()->email }}</flux:text>

            <form method="POST" action="{{ route('platform.logout') }}">
                @csrf
                <flux:button type="submit" size="sm" variant="ghost" icon="arrow-right-start-on-rectangle" data-test="platform-logout-button">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </flux:header>

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
