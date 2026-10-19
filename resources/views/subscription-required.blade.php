<x-layouts::app :title="__('Subscription required')">
    @php($status = tenant()->subscriptionStatus())

    <div class="max-w-2xl">
        <flux:heading size="xl" level="1">{{ __('Subscription required') }}</flux:heading>

        @if ($status->allowsAccess())
            <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="__('Your company subscription is active.')">
                <flux:callout.text>
                    <flux:link :href="route('dashboard')">{{ __('Go to the dashboard') }}</flux:link>
                </flux:callout.text>
            </flux:callout>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" data-test="subscription-required"
                :heading="$status === App\Enums\SubscriptionStatus::Expired
                    ? __('Your company\'s annual subscription has expired.')
                    : __('Your company does not have an active subscription yet.')">
                <flux:callout.text>
                    {{ __('The application is unavailable until the platform administrator renews or activates the subscription. Your account, your colleagues and your data are kept.') }}
                </flux:callout.text>
            </flux:callout>

            @can(App\Enums\TenantPermission::BillingView->value)
                <flux:button class="mt-6" icon="credit-card" :href="route('subscription.show')" wire:navigate>
                    {{ __('View subscription & billing') }}
                </flux:button>
            @else
                <flux:text class="mt-6">{{ __('Please contact your company administrator.') }}</flux:text>
            @endcan
        @endif
    </div>
</x-layouts::app>
