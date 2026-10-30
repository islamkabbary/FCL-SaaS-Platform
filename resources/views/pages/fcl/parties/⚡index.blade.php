<?php

use App\Actions\Fcl\CreateParty;
use App\Actions\Fcl\UpdateParty;
use App\Exceptions\Fcl\PossibleDuplicateParty;
use App\Models\Fcl\Party;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Parties')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    #[Locked]
    public ?int $editingId = null;

    public string $legalName = '';

    public string $taxNumber = '';

    public string $countryCode = '';

    public string $notes = '';

    public bool $isActive = true;

    /**
     * Existing parties that look like the one being saved, shown until the user confirms.
     *
     * @var list<array{id: int, legal_name: string, tax_number: string|null, is_active: bool}>
     */
    #[Locked]
    public array $similarParties = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Party::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }

        // A confirmation covers the warning that was shown, not a changed form.
        if (in_array($property, ['legalName', 'taxNumber'], true)) {
            $this->similarParties = [];
        }
    }

    /**
     * @return LengthAwarePaginator<int, Party>
     */
    #[Computed]
    public function parties(): LengthAwarePaginator
    {
        $this->authorize('viewAny', Party::class);

        $search = trim($this->search);
        $taxNumberKey = Party::taxNumberKey($search);

        return Party::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('legal_name', 'like', '%'.$search.'%')
                ->orWhere('tax_number', 'like', '%'.$search.'%')
                // "123456789" also finds "123-456-789".
                ->when($taxNumberKey !== '', fn ($query) => $query->orWhere('tax_number_key', 'like', '%'.$taxNumberKey.'%'))))
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('legal_name')
            ->orderBy('id')
            ->paginate(15);
    }

    public function create(): void
    {
        $this->authorize('create', Party::class);

        $this->resetForm();
        Flux::modal('party-form')->show();
    }

    public function edit(int $partyId): void
    {
        $party = Party::query()->findOrFail($partyId);
        $this->authorize('update', $party);

        $this->resetForm();
        $this->editingId = $party->id;
        $this->legalName = $party->legal_name;
        $this->taxNumber = (string) $party->tax_number;
        $this->countryCode = (string) $party->country_code;
        $this->notes = (string) $party->notes;
        $this->isActive = $party->is_active;

        Flux::modal('party-form')->show();
    }

    /**
     * Save the form. $duplicateConfirmed is sent by "Save anyway" after the warning; it
     * confirms the parties the warning lists, nothing else.
     */
    public function save(CreateParty $createParty, UpdateParty $updateParty, bool $duplicateConfirmed = false): void
    {
        $attributes = [
            'legal_name' => $this->legalName,
            'tax_number' => $this->taxNumber,
            'country_code' => $this->countryCode,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
        ];

        // A confirmation covers the parties of the warning that is shown. Changing the name
        // or tax number clears the warning (updating()), so new values are checked again,
        // and a similar party added meanwhile gives a new warning.
        $confirmedSimilarIds = $duplicateConfirmed ? array_column($this->similarParties, 'id') : [];

        $this->resetErrorBag();

        try {
            if ($this->editingId === null) {
                $createParty->handle(Auth::user(), $attributes, $confirmedSimilarIds);
            } else {
                $updateParty->handle(Auth::user(), Party::query()->findOrFail($this->editingId), $attributes, $confirmedSimilarIds);
            }
        } catch (ValidationException $exception) {
            // The action validates with the attribute names; the form uses property names.
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(Str::camel($key), $messages[0]);
            }

            return;
        } catch (PossibleDuplicateParty $exception) {
            $this->similarParties = $exception->similarParties
                ->map(fn (Party $party): array => $party->only(['id', 'legal_name', 'tax_number', 'is_active']))
                ->values()
                ->all();

            return;
        } catch (AuthorizationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::modal('party-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId === null ? __('Party created.') : __('Party updated.'));

        $this->resetForm();
        unset($this->parties);
    }

    protected function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'legalName', 'taxNumber', 'countryCode', 'notes', 'isActive', 'similarParties');
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Parties') }}</flux:heading>
            <flux:subheading>{{ __('Customers, importers, agents and carriers, shared by every branch of the company.') }}</flux:subheading>
        </div>

        @can('create', App\Models\Fcl\Party::class)
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-party-button">
                {{ __('New party') }}
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or tax number')" class="sm:max-w-sm" />

        <flux:select wire:model.live="status" class="sm:max-w-48">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table class="mt-6" :paginate="$this->parties">
        <flux:table.columns>
            <flux:table.column>{{ __('Legal name') }}</flux:table.column>
            <flux:table.column>{{ __('Tax / registration no.') }}</flux:table.column>
            <flux:table.column>{{ __('Country') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->parties as $party)
                <flux:table.row :key="$party->id">
                    <flux:table.cell>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $party->legal_name }}</div>
                        @if ($party->notes)
                            <flux:text size="sm" class="max-w-xs truncate" :title="$party->notes">{{ $party->notes }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $party->tax_number ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $party->country_code ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($party->is_active)
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @can('update', $party)
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $party->id }})">{{ __('Edit') }}</flux:button>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">
                        <flux:text>{{ __('No parties found.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="party-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit party') : __('New party') }}</flux:heading>

            {{-- Sent on blur, so a changed name or tax number clears a warning about the old one. --}}
            <flux:input wire:model.live.blur="legalName" :label="__('Legal name')" required maxlength="255" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model.live.blur="taxNumber" :label="__('Tax / registration no.')" maxlength="50" />
                <flux:input wire:model="countryCode" :label="__('Country')" :description="__('Two-letter code, e.g. EG')" maxlength="2" />
            </div>

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" maxlength="2000" />

            @if ($editingId)
                <flux:checkbox wire:model="isActive" :label="__('Active')" :description="__('Inactive parties stay on record but are hidden from the default list.')" />
            @endif

            @if ($similarParties !== [])
                <flux:callout variant="warning" icon="exclamation-triangle" role="alert" data-test="similar-parties">
                    <flux:callout.heading>{{ __('This party may already exist') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('These parties have the same name or tax number:') }}
                        <ul class="mt-2 list-disc ps-5">
                            @foreach ($similarParties as $similar)
                                <li wire:key="similar-{{ $similar['id'] }}">
                                    {{ $similar['legal_name'] }}{{ $similar['tax_number'] ? ' ('.$similar['tax_number'].')' : '' }}{{ $similar['is_active'] ? '' : ' · '.__('inactive') }}
                                </li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                @if ($similarParties !== [])
                    <flux:button variant="primary" wire:click="save(true)" data-test="save-party-anyway-button">{{ __('Save anyway') }}</flux:button>
                @else
                    <flux:button type="submit" variant="primary" data-test="save-party-button">{{ __('Save') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
</section>
