@php
    $otherLocale = collect(config('app.supported_locales'))->except(app()->getLocale())->keys()->first();
@endphp

<form method="POST" action="{{ tenancy()->initialized ? route('locale.switch') : route('central.locale.switch') }}">
    @csrf
    <input type="hidden" name="locale" value="{{ $otherLocale }}">
    <flux:button type="submit" size="sm" variant="ghost" icon="language" data-test="locale-switcher" :aria-label="__('Change language')">
        <span lang="{{ $otherLocale }}">{{ config('app.supported_locales')[$otherLocale] }}</span>
    </flux:button>
</form>
