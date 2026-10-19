<x-layouts::auth :title="__('Support access refused')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Support access refused')" :description="$message" />

        <flux:text class="text-center">{{ __('Start a new support session from the platform if access is still needed.') }}</flux:text>
    </div>
</x-layouts::auth>
