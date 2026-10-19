<?php

namespace App\Models;

use App\Enums\PlatformAuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An immutable record of a sensitive action a Platform Admin performed on a tenant.
 * Lives in the central database; records are only ever created, never changed.
 *
 * @property int $id
 * @property int|null $platform_admin_id
 * @property string $actor_email
 * @property string $tenant_id
 * @property PlatformAuditAction $action
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property-read PlatformAdmin|null $admin
 * @property-read Tenant $tenant
 */
class PlatformAuditLog extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

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
     * Record an action performed by a Platform Admin on a tenant.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(
        PlatformAdmin $actor,
        Tenant $tenant,
        PlatformAuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
    ): self {
        return self::query()->create([
            'platform_admin_id' => $actor->id,
            'actor_email' => $actor->email,
            'tenant_id' => $tenant->getTenantKey(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
        ]);
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
