<x-layouts::platform :title="__('Seat requests')">
    <flux:heading size="xl" level="1">{{ __('Seat requests') }}</flux:heading>
    <flux:subheading>{{ __('Additional seats are charged prorated until the current renewal date, calculated on the day of approval. Reductions apply at the next renewal.') }}</flux:subheading>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif
    @if (session('error'))
        <flux:callout variant="danger" icon="x-circle" class="mt-6" :heading="session('error')" />
    @endif

    <flux:heading size="lg" level="2" class="mt-8">{{ __('Pending (:count)', ['count' => $pending->count()]) }}</flux:heading>

    <div class="mt-4 flex flex-col gap-4">
        @forelse ($pending as $request)
            <flux:card data-test="pending-request-{{ $request->id }}">
                <div class="flex flex-col gap-4 lg:flex-row lg:justify-between">
                    <div>
                        <flux:heading>
                            #{{ $request->id }} · {{ $request->tenant->name }} · {{ $request->type->label() }}:
                            {{ $request->type === App\Enums\SeatRequestType::Increase ? '+'.$request->seats : __(':seats seats from the next renewal', ['seats' => $request->seats]) }}
                        </flux:heading>
                        <flux:text class="mt-1">{{ __('Requested :date by :name (:email)', ['date' => $request->created_at?->toDayDateTimeString(), 'name' => $request->requested_by_name, 'email' => $request->requested_by_email]) }}</flux:text>
                        @if ($request->request_note)
                            <flux:text class="mt-1">{{ __('Note') }}: {{ $request->request_note }}</flux:text>
                        @endif

                        @if ($previews[$request->id]['error'])
                            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-3" :heading="$previews[$request->id]['error']" />
                        @elseif ($previews[$request->id]['lines'] !== [])
                            <div class="mt-3 text-sm" data-test="approval-preview">
                                <div class="font-medium">{{ __('Charge if approved today') }}:</div>
                                @foreach ($previews[$request->id]['lines'] as $line)
                                    <div>{{ $line['description'] }}: {{ App\Billing\Money::format($line['amount'], $request->currency) }}</div>
                                @endforeach
                                <div class="font-medium">{{ __('Total') }}: {{ App\Billing\Money::format(array_sum(array_column($previews[$request->id]['lines'], 'amount')), $request->currency) }}</div>
                            </div>
                        @else
                            <flux:text class="mt-3">{{ __('No charge: the current term is unchanged. The renewal will use :seats seats.', ['seats' => $request->seats]) }}</flux:text>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2 lg:w-72">
                        <form method="POST" action="{{ route('platform.seat-requests.approve', $request) }}" class="flex flex-col gap-2">
                            @csrf
                            <flux:input name="review_note" size="sm" :placeholder="__('Note to the tenant (optional)')" />
                            <flux:button size="sm" variant="primary" type="submit" data-test="approve-request-{{ $request->id }}">{{ __('Approve') }}</flux:button>
                        </form>
                        <form method="POST" action="{{ route('platform.seat-requests.reject', $request) }}">
                            @csrf
                            <flux:button size="sm" variant="danger" type="submit" class="w-full" data-test="reject-request-{{ $request->id }}">{{ __('Reject') }}</flux:button>
                        </form>
                    </div>
                </div>
            </flux:card>
        @empty
            <flux:text>{{ __('No pending requests.') }}</flux:text>
        @endforelse
    </div>

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Recently reviewed') }}</flux:heading>
    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>#</flux:table.column>
            <flux:table.column>{{ __('Tenant') }}</flux:table.column>
            <flux:table.column>{{ __('Request') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Reviewed') }}</flux:table.column>
            <flux:table.column>{{ __('Charge') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($reviewed as $request)
                <flux:table.row :key="$request->id">
                    <flux:table.cell>{{ $request->id }}</flux:table.cell>
                    <flux:table.cell>{{ $request->tenant->name }}</flux:table.cell>
                    <flux:table.cell>{{ $request->type->label() }} · {{ $request->seats }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" inset="top bottom" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ $request->reviewed_at?->toDayDateTimeString() ?? '—' }} {{ $request->reviewer?->email }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($request->charge)
                            <flux:link :href="route('platform.charges.show', $request->charge)">{{ $request->charge->format($request->charge->amount) }}</flux:link>
                        @else
                            —
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('Nothing reviewed yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</x-layouts::platform>
