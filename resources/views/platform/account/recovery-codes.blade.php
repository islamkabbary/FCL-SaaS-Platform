<x-layouts::platform :title="__('Recovery codes')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Recovery codes') }}</flux:heading>

        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6"
            :heading="__('These codes are shown only once.')"
            :text="__('Store them somewhere safe. Each code signs you in once if you lose your authenticator device.')"
        />

        <ul class="mt-6 grid grid-cols-2 gap-2 font-mono text-sm" data-test="recovery-codes">
            @foreach ($codes as $code)
                <li class="rounded bg-zinc-100 px-3 py-2 dark:bg-zinc-700">{{ $code }}</li>
            @endforeach
        </ul>

        <flux:button class="mt-6" variant="primary" :href="route('platform.account.security')">{{ __('Done') }}</flux:button>
    </div>
</x-layouts::platform>
