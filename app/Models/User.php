<?php

namespace App\Models;

use App\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int $primary_branch_id
 * @property bool $is_tenant_admin Legacy marker of the initial Tenant Admin; authorization uses the Tenant Admin role.
 * @property bool $must_change_password
 * @property bool $is_active
 * @property Carbon|null $deactivated_at
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
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
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
     * Determine whether the user holds the built-in Tenant Admin role.
     */
    public function isTenantAdmin(): bool
    {
        return $this->hasRole(TenantRole::TenantAdmin->value);
    }

    /**
     * Determine whether the user is the only active user holding the Tenant Admin role.
     */
    public function isLastActiveTenantAdmin(): bool
    {
        return $this->is_active
            && $this->isTenantAdmin()
            && static::activeTenantAdminCount() === 1;
    }

    /**
     * Count the active users holding the Tenant Admin role.
     *
     * Inside a transaction this is a locking read: it reads the latest committed rows, whatever
     * the transaction's snapshot, and locks them until commit. Two admins demoting, deactivating
     * or deleting each other at once are therefore serialized, and the second one sees the
     * first one's change. The role is matched through joins rather than the role() scope: the
     * scope first looks the role up with a plain read (fixing the snapshot), and a subquery of
     * a locking read is not itself a locking read in MySQL.
     */
    public static function activeTenantAdminCount(): int
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
            ->where("{$tables['roles']}.name", TenantRole::TenantAdmin->value)
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
