<?php

namespace App\Models;

use App\Enums\PlatformAuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An immutable record of a sensitive platform action. Lives in the central database;
 * records are only ever created, never changed.
 *
 * The actor is a Platform Admin ("platform_admin"), a tenant user acting on their own
 * company's subscription or support access, e.g. requesting seats ("tenant_user"), or
 * the application itself ("system"), e.g. when a support session reaches its time limit
 * or a console command is run. Plan changes are platform-wide and have no tenant.
 *
 * @property int $id
 * @property string $actor_type
 * @property int|null $platform_admin_id
 * @property int|null $tenant_user_id
 * @property string $actor_email
 * @property string|null $tenant_id
 * @property PlatformAuditAction $action
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property-read PlatformAdmin|null $admin
 * @property-read Tenant|null $tenant
 */
class PlatformAuditLog extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    public const ActorPlatformAdmin = 'platform_admin';

    public const ActorTenantUser = 'tenant_user';

    public const ActorSystem = 'system';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => PlatformAuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Platform audit records cannot be changed.'));
        static::deleting(fn (): never => throw new LogicException('Platform audit records cannot be deleted.'));
    }

    /**
     * Record an action performed by a Platform Admin, on a tenant or platform-wide.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(
        PlatformAdmin $actor,
        ?Tenant $tenant,
        PlatformAuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
    ): self {
        return self::query()->create([
            'actor_type' => self::ActorPlatformAdmin,
            'platform_admin_id' => $actor->id,
            'actor_email' => $actor->email,
            'tenant_id' => $tenant?->getTenantKey(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
        ]);
    }

    /**
     * Record an action a tenant user performed on their own company's subscription.
     *
     * @param  array<string, mixed>|null  $newValues
     */
    public static function recordForTenantUser(User $actor, Tenant $tenant, PlatformAuditAction $action, ?array $newValues = null, ?string $reason = null): self
    {
        return self::query()->create([
            'actor_type' => self::ActorTenantUser,
            'tenant_user_id' => $actor->getKey(),
            'actor_email' => $actor->email,
            'tenant_id' => $tenant->getTenantKey(),
            'action' => $action,
            'new_values' => $newValues,
            'reason' => $reason,
        ]);
    }

    /**
     * Record an action taken by the application itself rather than by a person.
     *
     * @param  array<string, mixed>|null  $newValues
     */
    public static function recordSystem(?Tenant $tenant, PlatformAuditAction $action, ?array $newValues = null, ?string $reason = null): self
    {
        return self::query()->create([
            'actor_type' => self::ActorSystem,
            'actor_email' => 'system',
            'tenant_id' => $tenant?->getTenantKey(),
            'action' => $action,
            'new_values' => $newValues,
            'reason' => $reason,
        ]);
    }

    public function isSystemAction(): bool
    {
        return $this->actor_type === self::ActorSystem;
    }

    public function isTenantUserAction(): bool
    {
        return $this->actor_type === self::ActorTenantUser;
    }

    /**
     * Get the Platform Admin who performed the action.
     *
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    /**
     * Get the tenant the action was performed on.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
