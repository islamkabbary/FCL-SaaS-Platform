<x-layouts::platform :title="__('Billing')">
    <flux:heading size="xl" level="1">{{ __('Charges and payments') }}</flux:heading>
    <flux:subheading>{{ __('Internal commercial billing records (not tax invoices). Payments are recorded manually.') }}</flux:subheading>

    <div class="mt-6 flex gap-2">
        <flux:button size="sm" :variant="$outstandingOnly ? 'ghost' : 'filled'" :href="route('platform.charges.index')">{{ __('All charges') }}</flux:button>
        <flux:button size="sm" :variant="$outstandingOnly ? 'filled' : 'ghost'" :href="route('platform.charges.index', ['outstanding' => 1])">{{ __('Outstanding only') }}</flux:button>
    </div>

    @include('platform.charges.partials.table', ['charges' => $charges, 'showTenant' => true])

    <div class="mt-6">{{ $charges->links() }}</div>
</x-layouts::platform>
