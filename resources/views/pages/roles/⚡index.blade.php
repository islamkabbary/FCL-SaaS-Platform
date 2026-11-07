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
                        <div class="font-medium text-zinc-800 dark:text-white">{{ App\Enums\TenantRole::displayName($role->name) }}</div>
                        @if (App\Enums\TenantRole::isSystem($role->name))
                            <flux:text size="sm">{{ __('Built-in') }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @if ($role->name === App\Enums\TenantRole::SuperAdmin->value)
                                <flux:badge size="sm" color="amber" data-test="super-admin-all-permissions">{{ __('All permissions (exempt from every check)') }}</flux:badge>
                            @endif
                            @forelse ($role->permissions as $permission)
                                <flux:badge size="sm" wire:key="role-{{ $role->id }}-permission-{{ $permission->id }}">{{ App\Enums\TenantPermission::tryFrom($permission->name)?->label() ?? $permission->name }}</flux:badge>
                            @empty
                                @if ($role->name !== App\Enums\TenantRole::SuperAdmin->value)
                                    <flux:text size="sm">{{ __('No permissions') }}</flux:text>
                                @endif
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

    <flux:modal name="role-form" class="w-full md:max-w-3xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit role') : __('New role') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required :disabled="App\Enums\TenantRole::isSystem($name) && $editingId !== null" />

            <div>
                <flux:heading size="sm">{{ __('Permissions') }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('You can only grant permissions you hold yourself.') }}</flux:text>

                <div class="mt-3 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="permission-matrix">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Section') }}</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">{{ __('Show') }}</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">{{ __('Create') }}</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">{{ __('Edit') }}</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">{{ __('Delete') }}</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Other actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach (App\Enums\TenantPermission::byResource() as $resource => $permissions)
                                <tr wire:key="permission-row-{{ $resource }}">
                                    <th scope="row" class="px-3 py-2 text-start font-medium text-zinc-800 dark:text-white">{{ App\Enums\TenantPermission::resourceLabel($resource) }}</th>
                                    @foreach (App\Enums\TenantPermission::BasicActions as $action)
                                        <td class="px-3 py-2 text-center">
                                            @if ($permission = App\Enums\TenantPermission::tryFrom($resource.'.'.$action))
                                                <div class="inline-flex" title="{{ $permission->label() }}">
                                                    <flux:checkbox wire:model="selectedPermissions" :value="$permission->value" :aria-label="$permission->label()"
                                                        :disabled="! auth()->user()->can($permission->value)" wire:key="permission-{{ $permission->value }}" />
                                                </div>
                                            @else
                                                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-2">
                                        <div class="flex flex-col gap-2">
                                            @foreach ($permissions as $permission)
                                                @unless ($permission->isBasic())
                                                    <flux:checkbox wire:model="selectedPermissions" :value="$permission->value" :label="$permission->label()"
                                                        :disabled="! auth()->user()->can($permission->value)" wire:key="permission-{{ $permission->value }}" />
                                                @endunless
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-role-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
