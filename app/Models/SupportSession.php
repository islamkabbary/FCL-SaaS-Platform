<?php

namespace App\Models;

use App\Enums\ImpersonationEndReason;
use App\Enums\ImpersonationKind;
use App\Enums\ImpersonationStatus;
use App\Models\Concerns\IsImpersonationSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A Platform Admin's temporary, restricted access to a tenant as one of its Tenant
 * Admins. Lives in the central database.
 *
 * The session starts "pending": the Platform Admin's browser carries a single-use
 * handoff credential (only its SHA-256 hash is stored) to the tenant subdomain, where
 * it is consumed once. Its lifetime counts from creation, not from the handoff.
 *
 * @property int $id
 * @property int|null $platform_admin_id
 * @property string $actor_name
 * @property string $actor_email
 * @property string $tenant_id
 * @property int $target_user_id
 * @property string $target_name
 * @property string $target_email
 * @property string $reason
 * @property string|null $handoff_token_hash
 * @property Carbon $handoff_expires_at
 * @property Carbon|null $handoff_consumed_at
 * @property string|null $ended_by_type
 * @property string|null $started_ip
 * @property string|null $handoff_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PlatformAdmin|null $admin
 * @property-read Tenant $tenant
 */
class SupportSession extends Model
{
    use CentralConnection, IsImpersonationSession;

    public const EndedByPlatformAdmin = 'platform_admin';

    public const EndedByTenantUser = 'tenant_user';

    public const EndedBySystem = 'system';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['handoff_token_hash'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handoff_expires_at' => 'datetime',
            'handoff_consumed_at' => 'datetime',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => ImpersonationEndReason::class,
        ];
    }

    protected function mutableAttributes(): array
    {
        return ['handoff_token_hash', 'handoff_consumed_at', 'handoff_ip', 'ended_at', 'end_reason', 'ended_by_type', 'ended_by_email'];
    }

    public function kind(): ImpersonationKind
    {
        return ImpersonationKind::Support;
    }

    public function status(): ImpersonationStatus
    {
        if ($this->ended_at !== null) {
            return $this->end_reason->status();
        }

        if ($this->handoff_consumed_at === null) {
            // A handoff that was never used within its validity can no longer start the session.
            return $this->handoff_expires_at->isPast() || $this->expires_at->isPast()
                ? ImpersonationStatus::Expired
                : ImpersonationStatus::Pending;
        }

        return $this->expires_at->isPast() ? ImpersonationStatus::Expired : ImpersonationStatus::Active;
    }

    /**
     * Whether the session can still be revoked: pending or active.
     */
    public function isRevocable(): bool
    {
        return in_array($this->status(), [ImpersonationStatus::Pending, ImpersonationStatus::Active], true);
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return HasMany<SupportSessionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SupportSessionEvent::class);
    }
}
