<?php

namespace App\Models\Concerns;

use App\Enums\ImpersonationEndReason;
use App\Enums\ImpersonationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Shared lifecycle of support sessions (central) and employee impersonation sessions
 * (tenant). The identity, reason and timestamps are immutable once created; only the
 * fields listed in mutableAttributes() can change, and a session ends at most once.
 *
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property ImpersonationEndReason|null $end_reason
 * @property string|null $ended_by_email
 */
trait IsImpersonationSession
{
    protected static function bootIsImpersonationSession(): void
    {
        static::updating(function (self $session): void {
            $changed = array_diff(array_keys($session->getDirty()), [...$session->mutableAttributes(), 'updated_at']);

            if ($changed !== [] || $session->getOriginal('ended_at') !== null) {
                throw new LogicException(class_basename(static::class).' records cannot be changed.');
            }
        });

        static::deleting(fn (): never => throw new LogicException(class_basename(static::class).' records cannot be deleted.'));
    }

    /**
     * Attributes that may change after creation.
     *
     * @return list<string>
     */
    abstract protected function mutableAttributes(): array;

    /**
     * The user id the session acts as, in the tenant database.
     */
    public function targetUserId(): int
    {
        return (int) $this->target_user_id;
    }

    public function status(): ImpersonationStatus
    {
        if ($this->ended_at !== null) {
            return $this->end_reason->status();
        }

        return $this->expires_at->isPast() ? ImpersonationStatus::Expired : ImpersonationStatus::Active;
    }

    /**
     * Whether the session may still be used: not ended and within its lifetime.
     */
    public function isOpen(): bool
    {
        return $this->status() === ImpersonationStatus::Active;
    }

    /**
     * End the session. Atomic: returns false if it had already been ended (e.g. by a
     * concurrent request), in which case nothing is changed.
     *
     * @param  array<string, mixed>  $extra
     */
    public function end(ImpersonationEndReason $reason, ?string $endedByEmail = null, array $extra = []): bool
    {
        $values = [
            'ended_at' => now(),
            'end_reason' => $reason->value,
            'ended_by_email' => $endedByEmail,
            ...$extra,
        ];

        $ended = static::query()->whereKey($this->getKey())->whereNull('ended_at')->update($values) === 1;

        $this->refresh();

        return $ended;
    }

    /**
     * Sessions not ended and not yet expired.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    /**
     * Record something done, or refused, during the session.
     */
    public function recordEvent(string $type, string $action, ?string $httpMethod = null): void
    {
        $this->events()->create([
            'type' => $type,
            'action' => mb_substr($action, 0, 255),
            'http_method' => $httpMethod,
            'created_at' => now(),
        ]);
    }
}
