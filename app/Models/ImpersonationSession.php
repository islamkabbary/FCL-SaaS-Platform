<?php

namespace App\Models;

use App\Enums\ImpersonationEndReason;
use App\Enums\ImpersonationKind;
use App\Models\Concerns\IsImpersonationSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Tenant Admin viewing the application as one of their employees. Lives in the
 * tenant database (tenant-local audit record).
 *
 * @property int $id
 * @property int $impersonator_user_id
 * @property string $impersonator_name
 * @property string $impersonator_email
 * @property int $target_user_id
 * @property string $target_name
 * @property string $target_email
 * @property string $reason
 * @property string|null $started_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImpersonationSession extends Model
{
    use IsImpersonationSession;

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
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => ImpersonationEndReason::class,
        ];
    }

    protected function mutableAttributes(): array
    {
        return ['ended_at', 'end_reason', 'ended_by_email'];
    }

    public function kind(): ImpersonationKind
    {
        return ImpersonationKind::Employee;
    }

    /**
     * @return HasMany<ImpersonationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ImpersonationEvent::class);
    }
}
