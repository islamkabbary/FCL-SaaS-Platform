<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        @include('partials.impersonation-banner')

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
