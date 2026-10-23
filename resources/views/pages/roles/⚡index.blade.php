<?php

use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\SaveRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Roles & Permissions')] class extends Component {
    public ?int $editingId = null;

    public string $name = '';

    /** @var list<string> */
    public array $selectedPermissions = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->with('permissions')->withCount('users')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('create', Role::class);

        $this->resetForm();
        Flux::modal('role-form')->show();
    }

    public function edit(int $roleId): void
    {
        $role = Role::query()->findOrFail($roleId);
        $this->authorize('update', $role);

        $this->resetErrorBag();
        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->all();

        Flux::modal('role-form')->show();
    }

    public function save(SaveRole $saveRole): void
    {
        // Stored normalised, so the uniqueness check compares the name that will be saved.
        $this->name = TenantRole::normalizeName($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(Role::class, 'name')->where('guard_name', 'web')->ignore($this->editingId)],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', Rule::in(TenantPermission::values())],
        ]);

        $role = $this->editingId === null ? null : Role::query()->findOrFail($this->editingId);

        try {
            $saveRole->handle(Auth::user(), $role, $validated['name'], array_values($validated['selectedPermissions']));
        } catch (AuthorizationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::modal('role-form')->close();
        Flux::toast(variant: 'success', text: $role === null ? __('Role created.') : __('Role updated.'));

        $this->resetForm();
        unset($this->roles);
    }

    public function delete(int $roleId, DeleteRole $deleteRole): void
    {
        $role = Role::query()->findOrFail($roleId);

        try {
            $deleteRole->handle(Auth::user(), $role);
        } catch (AuthorizationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Role deleted.'));
        unset($this->roles);
    }

    protected function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'name', 'selectedPermissions');
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Roles & Permissions') }}</flux:heading>
            <flux:subheading>{{ __('Roles group permissions; employees receive permissions through their roles.') }}</flux:subheading>
        </div>

        @can('create', Spatie\Permission\Models\Role::class)
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-role-button">
                {{ __('New role') }}
            </flux:button>
        @endcan
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Permissions') }}</flux:table.column>
            <flux:table.column>{{ __('Employees') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->roles as $role)
                <flux:table.row :key="$role->id">
                    <flux:table.cell>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $role->name }}</div>
                        @if (App\Enums\TenantRole::isSystem($role->name))
                            <flux:text size="sm">{{ __('Built-in') }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($role->permissions as $permission)
                                <flux:badge size="sm" wire:key="role-{{ $role->id }}-permission-{{ $permission->id }}">{{ $permission->name }}</flux:badge>
                            @empty
                                <flux:text size="sm">{{ __('No permissions') }}</flux:text>
                            @endforelse
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $role->users_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-2">
                            @can('update', $role)
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $role->id }})">{{ __('Edit') }}</flux:button>
                            @endcan
                            @can('delete', $role)
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $role->id }})" wire:confirm="{{ __('Delete this role? Employees holding it will lose its permissions.') }}">{{ __('Delete') }}</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="role-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit role') : __('New role') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required :disabled="App\Enums\TenantRole::isSystem($name) && $editingId !== null" />

            <flux:checkbox.group wire:model="selectedPermissions" :label="__('Permissions')" :description="__('You can only grant permissions you hold yourself.')">
                @foreach (App\Enums\TenantPermission::cases() as $permission)
                    <flux:checkbox :value="$permission->value" :label="$permission->label()" :description="$permission->value"
                        :disabled="! auth()->user()->can($permission->value)" wire:key="permission-{{ $permission->value }}" />
                @endforeach
            </flux:checkbox.group>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-role-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
