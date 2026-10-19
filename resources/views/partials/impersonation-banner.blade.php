@php($impersonation = \App\Impersonation\ActiveImpersonation::current())

@if ($impersonation)
    <div role="alert" data-test="impersonation-banner"
        class="sticky top-0 z-40 -mx-6 -mt-6 mb-6 flex flex-col gap-3 border-b-2 border-amber-500 bg-amber-100 px-6 py-3 text-amber-950 sm:flex-row sm:items-center sm:justify-between lg:-mx-8 lg:-mt-8 lg:px-8 dark:bg-amber-900 dark:text-amber-50"
        x-data="{
            endsAt: {{ $impersonation->expiresAt()->getTimestamp() * 1000 }},
            left: '',
            tick() {
                const seconds = Math.max(0, Math.floor((this.endsAt - Date.now()) / 1000));
                this.left = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
            },
        }"
        x-init="tick(); setInterval(() => tick(), 1000)">
        <div class="text-sm">
            <div class="font-semibold">
                {{ __('Impersonation active') }} · {{ $impersonation->kind()->label() }}: {{ $impersonation->actorName() }} &lt;{{ $impersonation->actorEmail() }}&gt;
            </div>
            <div>
                {{ __('Viewing as :name <:email>. Restricted session: changes are disabled.', ['name' => $impersonation->targetName(), 'email' => $impersonation->targetEmail()]) }}
                {{ __('Ends at :time', ['time' => $impersonation->expiresAt()->format('H:i')]) }} (<span x-text="left" data-test="impersonation-time-left"></span>).
            </div>
        </div>

        <form method="POST" action="{{ route('impersonation.end') }}">
            @csrf
            <flux:button type="submit" size="sm" variant="primary" icon="arrow-uturn-left" data-test="end-impersonation-button">{{ __('End impersonation') }}</flux:button>
        </form>
    </div>
@endif
