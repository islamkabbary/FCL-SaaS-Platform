<x-layouts::platform :title="__('Charge #:id', ['id' => $charge->id])">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('platform.charges.index')">{{ __('Billing') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('platform.subscriptions.show', $charge->tenant)">{{ $charge->tenant->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Charge #:id', ['id' => $charge->id]) }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-4 flex items-center gap-3">
        <flux:heading size="xl" level="1">{{ $charge->description }}</flux:heading>
        <flux:badge :color="$charge->status()->color()" data-test="charge-status">{{ $charge->status()->label() }}</flux:badge>
    </div>
    <flux:subheading>{{ $charge->type->label() }} · {{ __('issued :date', ['date' => $charge->issued_on->toDateString()]) }} · {{ $charge->currency }}</flux:subheading>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Line') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Unit') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($charge->lines as $line)
                <flux:table.row :key="$line->id">
                    <flux:table.cell class="whitespace-normal">
                        {{ $line->description }}
                        @if (isset($line->calculation['remaining_days']))
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __(':seats × :price × :remaining / :total days, rounded half up', ['seats' => $line->calculation['seats'], 'price' => $charge->format($line->calculation['annual_seat_price']), 'remaining' => $line->calculation['remaining_days'], 'total' => $line->calculation['total_days']]) }}
                            </div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $line->quantity }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($line->unit_amount) }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($line->amount) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
            <flux:table.row>
                <flux:table.cell variant="strong">{{ __('Total') }}</flux:table.cell>
                <flux:table.cell></flux:table.cell>
                <flux:table.cell></flux:table.cell>
                <flux:table.cell align="end" variant="strong" class="tabular-nums" data-test="charge-total">{{ $charge->format($charge->amount) }}</flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Payments') }}</flux:heading>
    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>{{ __('Paid on') }}</flux:table.column>
            <flux:table.column>{{ __('Reference') }}</flux:table.column>
            <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($charge->payments as $payment)
                <flux:table.row :key="$payment->id">
                    <flux:table.cell>{{ $payment->paid_on->toDateString() }}</flux:table.cell>
                    <flux:table.cell>{{ $payment->reference ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $payment->recorder?->email ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $charge->format($payment->amount) }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">{{ __('No payments recorded.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
            <flux:table.row>
                <flux:table.cell variant="strong">{{ __('Balance') }}</flux:table.cell>
                <flux:table.cell></flux:table.cell>
                <flux:table.cell></flux:table.cell>
                <flux:table.cell align="end" variant="strong" class="tabular-nums" data-test="charge-balance">{{ $charge->format($charge->balance()) }}</flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>

    @if ($charge->balance() > 0)
        <flux:card class="mt-8 max-w-xl">
            <flux:heading size="lg">{{ __('Record a payment') }}</flux:heading>
            <flux:text class="mt-1">{{ __('For money received outside the platform. Do not enter card or bank credentials.') }}</flux:text>

            <form method="POST" action="{{ route('platform.charges.payments.store', $charge) }}" class="mt-6 flex flex-col gap-6">
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
                <flux:input name="amount" inputmode="decimal" :label="__('Amount (:currency)', ['currency' => $charge->currency])" :value="old('amount', App\Billing\Money::toDecimal($charge->balance(), $charge->currency))" required />
                <flux:input name="paid_on" type="date" :label="__('Paid on')" :value="old('paid_on', now()->toDateString())" :max="now()->toDateString()" required />
                <flux:input name="reference" :label="__('External reference (optional)')" :value="old('reference')" :description="__('E.g. a bank transfer number. Non-sensitive values only.')" />
                <flux:textarea name="note" :label="__('Note (optional)')" rows="2">{{ old('note') }}</flux:textarea>
                <div><flux:button variant="primary" type="submit" data-test="record-payment-button">{{ __('Record payment') }}</flux:button></div>
            </form>
        </flux:card>
    @endif
</x-layouts::platform>
