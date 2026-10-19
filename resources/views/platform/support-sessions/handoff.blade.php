<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Opening support session')])
        <meta name="referrer" content="no-referrer">
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        <div class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 p-6">
            <flux:heading size="xl" level="1">{{ __('Opening the support session') }}</flux:heading>
            <flux:text>{{ __(':company as :target. This page continues automatically; if it does not, select Continue within one minute.', ['company' => $tenant->name, 'target' => $session->target_email]) }}</flux:text>

            {{-- The single-use credential is posted, never placed in a URL. --}}
            <form id="support-handoff" method="POST" action="{{ $action }}">
                <input type="hidden" name="token" value="{{ $token }}">
                <flux:button variant="primary" type="submit" data-test="continue-handoff-button">{{ __('Continue') }}</flux:button>
            </form>
        </div>

        <script>document.getElementById('support-handoff').submit();</script>
    </body>
</html>
