<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\SetEmployeeActivation;
use App\Actions\Employees\UpdateEmployee;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new #[Title('Employees')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $role = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    /** @var list<int|string> */
    public array $selectedRoles = [];

    /**
     * Shown once after an employee is created, then discarded. Never stored server-side.
     */
    public ?string $temporaryPassword = null;

    public ?string $createdEmployeeEmail = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status', 'role'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function employees(): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'primaryBranch'])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($this->role !== '', fn ($query) => $query->whereHas('roles', fn ($query) => $query->whereKey((int) $this->role)))
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('create', User::class);

        $this->resetForm();
        Flux::modal('employee-form')->show();
    }

    public function edit(int $employeeId): void
    {
        $employee = User::query()->findOrFail($employeeId);
        $this->authorize('update', $employee);

        $this->resetErrorBag();
        $this->editingId = $employee->id;
        $this->name = $employee->name;
        $this->email = $employee->email;
        $this->selectedRoles = $employee->roles->pluck('id')->map(fn ($id) => (string) $id)->all();

        Flux::modal('employee-form')->show();
    }

    public function save(CreateEmployee $createEmployee, UpdateEmployee $updateEmployee): void
    {
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->editingId)],
            'selectedRoles' => ['array'],
            'selectedRoles.*' => ['integer', Rule::exists(Role::class, 'id')],
        ]);

        $roleIds = array_map('intval', $validated['selectedRoles']);
        $actor = Auth::user();

        try {
            if ($this->editingId === null) {
                $created = $createEmployee->handle($actor, ['name' => $validated['name'], 'email' => $validated['email']], $roleIds);

                $this->temporaryPassword = $created->temporaryPassword;
                $this->createdEmployeeEmail = $created->employee->email;

                Flux::modal('employee-form')->close();
                Flux::modal('employee-created')->show();
            } else {
                $employee = User::query()->findOrFail($this->editingId);
                $canAssignRoles = $actor->can('assignRoles', $employee);

                $updateEmployee->handle(
                    $actor,
                    $employee,
                    ['name' => $validated['name'], 'email' => $validated['email']],
                    $canAssignRoles ? $roleIds : null,
                );

                Flux::modal('employee-form')->close();
                Flux::toast(variant: 'success', text: __('Employee updated.'));
            }
        } catch (AuthorizationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->resetForm();
        unset($this->employees);
    }

    public function deactivate(int $employeeId, SetEmployeeActivation $setEmployeeActivation): void
    {
        $this->setActivation($employeeId, false, $setEmployeeActivation);
    }

    public function activate(int $employeeId, SetEmployeeActivation $setEmployeeActivation): void
    {
        $this->setActivation($employeeId, true, $setEmployeeActivation);
    }

    public function forgetTemporaryPassword(): void
    {
        $this->temporaryPassword = null;
        $this->createdEmployeeEmail = null;
    }

    protected function setActivation(int $employeeId, bool $active, SetEmployeeActivation $setEmployeeActivation): void
    {
        $employee = User::query()->findOrFail($employeeId);

        try {
            $setEmployeeActivation->handle(Auth::user(), $employee, $active);
        } catch (AuthorizationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $active ? __('Employee activated.') : __('Employee deactivated.'));
        unset($this->employees);
    }

    protected function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'name', 'email', 'selectedRoles');
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Employees') }}</flux:heading>
            <flux:subheading>{{ __('Manage the people who can access your company account.') }}</flux:subheading>
        </div>

        @can('create', App\Models\User::class)
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-employee-button">
                {{ __('New employee') }}
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search name or email')" class="sm:max-w-xs" />

        <flux:select wire:model.live="status" class="sm:max-w-40">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="role" class="sm:max-w-48">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            @foreach ($this->roles as $roleOption)
                <flux:select.option :value="$roleOption->id" wire:key="role-filter-{{ $roleOption->id }}">{{ $roleOption->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table class="mt-4" :paginate="$this->employees">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Primary branch') }}</flux:table.column>
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->employees as $employee)
                <flux:table.row :key="$employee->id">
                    <flux:table.cell>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $employee->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $employee->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $employee->primaryBranch->name }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($employee->roles as $employeeRole)
                                <flux:badge size="sm" wire:key="employee-{{ $employee->id }}-role-{{ $employeeRole->id }}">{{ $employeeRole->name }}</flux:badge>
                            @endforeach
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($employee->is_active)
                            <flux:badge size="sm" color="green" inset="top bottom">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="red" inset="top bottom">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('update', $employee)
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $employee->id }})">{{ __('Edit') }}</flux:button>
                            @endcan

                            @if ($employee->is_active)
                                @can('deactivate', $employee)
                                    <flux:button size="sm" variant="ghost" icon="no-symbol" wire:click="deactivate({{ $employee->id }})" wire:confirm="{{ __('Deactivate this employee? They will be signed out immediately.') }}">{{ __('Deactivate') }}</flux:button>
                                @endcan
                            @else
                                @can('activate', $employee)
                                    <flux:button size="sm" variant="ghost" icon="check-circle" wire:click="activate({{ $employee->id }})">{{ __('Activate') }}</flux:button>
                                @endcan
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">{{ __('No employees match your filters.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="employee-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit employee') : __('New employee') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" type="email" :label="__('Email')" required />

            @if ($editingId === null)
                <flux:text>{{ __('The employee joins the Main Branch and receives a temporary password that must be changed at first login.') }}</flux:text>
            @endif

            @can('roles.assign')
                <flux:checkbox.group wire:model="selectedRoles" :label="__('Roles')" :description="$editingId === null ? __('Leave empty to assign the Employee role.') : null">
                    @foreach ($this->roles as $roleOption)
                        <flux:checkbox :value="(string) $roleOption->id" :label="$roleOption->name" wire:key="role-option-{{ $roleOption->id }}" />
                    @endforeach
                </flux:checkbox.group>
            @endcan

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-employee-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="employee-created" class="md:w-lg" wire:close="forgetTemporaryPassword">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Employee created') }}</flux:heading>

            <flux:callout variant="warning" icon="exclamation-triangle"
                :heading="__('This temporary password is shown only once.')"
                :text="__('Share it with the employee through a secure channel. They must change it at first login.')"
            />

            @if ($temporaryPassword)
                <flux:text>{{ $createdEmployeeEmail }}</flux:text>
                <flux:input :label="__('Temporary password')" :value="$temporaryPassword" readonly copyable data-test="employee-temporary-password" />
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Done') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
