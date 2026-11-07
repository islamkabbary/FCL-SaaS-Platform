<?php

namespace App\Models;

use App\Concerns\HasUsername;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use BackedEnum;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int $primary_branch_id
 * @property bool $is_tenant_admin Legacy marker of the initial Super Admin; authorization uses the Super Admin role.
 * @property bool $must_change_password
 * @property bool $is_active
 * @property Carbon|null $deactivated_at
 * @property string|null $locale
 * @property string $username
 * @property Carbon|null $username_changed_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $primaryBranch
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUsername, Notifiable {
        HasRoles::hasPermissionTo as traitHasPermissionTo;
    }

    /**
     * The guard used for roles and permissions. Tenant users always authenticate through "web".
     */
    protected string $guard_name = 'web';

    /**
     * Default attribute values, matching the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The language of the mail and notifications sent to this account.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'username_changed_at' => 'datetime',
            'password' => 'hashed',
            'is_tenant_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Scope the query to active (not deactivated) users.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Determine whether the user holds the built-in Super Admin role (the company's owner).
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(TenantRole::SuperAdmin->value);
    }

    /**
     * A Super Admin is exempt from every permission check: they hold every permission of the
     * catalog without any permission row, including permissions added later.
     *
     * Only catalog names are granted this way. spatie/laravel-permission's Gate hook also
     * asks this method about policy abilities such as "update"; answering yes to those would
     * skip the policy, and with it the rules that bind a Super Admin too (no deactivating
     * yourself, the last Super Admin stays, built-in roles stay unchanged).
     *
     * @param  string|int|Permission|BackedEnum  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->isSuperAdmin() && in_array($this->permissionName($permission), TenantPermission::values(), true)) {
            return true;
        }

        return $this->traitHasPermissionTo($permission, $guardName);
    }

    /**
     * The permission's name, or null for a form that does not carry one (an id).
     *
     * @param  string|int|Permission|BackedEnum  $permission
     */
    protected function permissionName($permission): ?string
    {
        return match (true) {
            $permission instanceof BackedEnum => (string) $permission->value,
            $permission instanceof Permission => $permission->name,
            is_string($permission) => $permission,
            default => null,
        };
    }

    /**
     * Determine whether the user is the only active user holding the Super Admin role.
     */
    public function isLastActiveSuperAdmin(): bool
    {
        return $this->is_active
            && $this->isSuperAdmin()
            && static::activeSuperAdminCount() === 1;
    }

    /**
     * Count the active users holding the Super Admin role.
     *
     * Inside a transaction this is a locking read: it reads the latest committed rows, whatever
     * the transaction's snapshot, and locks them until commit. Two admins demoting, deactivating
     * or deleting each other at once are therefore serialized, and the second one sees the
     * first one's change. The role is matched through joins rather than the role() scope: the
     * scope first looks the role up with a plain read (fixing the snapshot), and a subquery of
     * a locking read is not itself a locking read in MySQL.
     */
    public static function activeSuperAdminCount(): int
    {
        $tables = config('permission.table_names');
        $modelKey = config('permission.column_names.model_morph_key');

        $query = static::query();
        $morphClass = $query->getModel()->getMorphClass();

        $query
            ->join($tables['model_has_roles'], function ($join) use ($tables, $modelKey, $morphClass): void {
                $join->on("{$tables['model_has_roles']}.{$modelKey}", '=', 'users.id')
                    ->where("{$tables['model_has_roles']}.model_type", $morphClass);
            })
            ->join($tables['roles'], "{$tables['roles']}.id", '=', "{$tables['model_has_roles']}.role_id")
            ->where("{$tables['roles']}.name", TenantRole::SuperAdmin->value)
            ->where("{$tables['roles']}.guard_name", 'web')
            ->where('users.is_active', true);

        if ($query->getConnection()->transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        return $query->distinct()->count('users.id');
    }

    /**
     * Get the branch the employee primarily belongs to.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
