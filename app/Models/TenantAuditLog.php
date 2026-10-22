<?php

namespace App\Models;

use App\Impersonation\ActiveImpersonation;
use App\Impersonation\DeniedDuringImpersonation;
use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\IsAppendOnly;
use BackedEnum;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * An immutable record of a business action inside the company. Lives in the tenant
 * database, separate from the central PlatformAuditLog; FCL Operations are its first users.
 *
 * Records are written only through record(), in the same database transaction as the
 * change they describe, so a change and its audit record are committed or rolled back
 * together. They are never changed or deleted through the application.
 *
 * The actor is the authenticated tenant user ("tenant_user") or the application itself
 * ("system"). During an impersonation the effective actor is the impersonated user and
 * the original actor (a Platform Admin or a Tenant Admin) is recorded next to it.
 *
 * @property int $id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $actor_type
 * @property int|null $actor_user_id
 * @property string $actor_name
 * @property string $actor_email
 * @property string|null $impersonation_kind
 * @property int|null $impersonation_session_id
 * @property string|null $original_actor_type
 * @property int|null $original_actor_id
 * @property string|null $original_actor_name
 * @property string|null $original_actor_email
 * @property string|null $correlation_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $metadata
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class TenantAuditLog extends Model implements DeniedDuringImpersonation
{
    use IsAppendOnly;

    public const UPDATED_AT = null;

    public const ActorTenantUser = 'tenant_user';

    public const ActorSystem = 'system';

    public const ActorPlatformAdmin = 'platform_admin';

    /**
     * The request attribute holding the correlation id shared by all records of a request.
     */
    public const CorrelationAttribute = 'tenant_audit.correlation_id';

    /**
     * Keys whose values are never stored, at any depth of the recorded values.
     */
    public const RedactedKeyPattern = '/password|secret|token|remember|credential|api[_-]?key|signature|cookie|^session(_id)?$/i';

    public const Redacted = '[redacted]';

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
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record a business action. Call it inside the transaction that performs the change.
     *
     * @param  BackedEnum  $action  A string-backed action enum of the calling feature.
     * @param  User|null  $actor  The acting tenant user; null for the application itself.
     * @param  Model|null  $subject  The record the action concerns (a tenant model).
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata  Small, non-sensitive context; never a request payload.
     */
    public static function record(
        BackedEnum $action,
        ?User $actor,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): self {
        if (! tenancy()->initialized) {
            throw new LogicException('Tenant audit records can only be written in tenant context.');
        }

        if (! is_string($action->value)) {
            throw new LogicException('Tenant audit actions must be string-backed enums.');
        }

        $record = new self;

        if ($record->getConnection()->transactionLevel() === 0) {
            throw new LogicException('Tenant audit records must be written inside the transaction of the change they describe.');
        }

        if ($subject !== null && $subject->getConnectionName() === config('tenancy.database.central_connection')) {
            throw new LogicException('Tenant audit records can only describe tenant records.');
        }

        $record->fill([
            'action' => $action->value,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            ...self::actorAttributes($actor),
            'correlation_id' => self::correlationId(),
            'old_values' => self::redact($oldValues),
            'new_values' => self::redact($newValues),
            'metadata' => self::redact($metadata),
            'reason' => $reason,
        ])->save();

        return $record;
    }

    /**
     * Remove the values of sensitive keys, recursively.
     *
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    public static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::RedactedKeyPattern, $key) === 1) {
                $values[$key] = self::Redacted;
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    /**
     * The effective actor, and the original actor when the actor is being impersonated.
     *
     * @return array<string, mixed>
     */
    protected static function actorAttributes(?User $actor): array
    {
        if ($actor === null) {
            return [
                'actor_type' => self::ActorSystem,
                'actor_name' => 'System',
                'actor_email' => 'system',
            ];
        }

        $attributes = [
            'actor_type' => self::ActorTenantUser,
            'actor_user_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
        ];

        $active = ActiveImpersonation::current();

        if ($active === null || $active->targetUserId() !== $actor->getKey()) {
            return $attributes;
        }

        $session = $active->session;

        return [
            ...$attributes,
            'impersonation_kind' => $active->kind()->value,
            'impersonation_session_id' => $session->id,
            'original_actor_type' => $session instanceof SupportSession ? self::ActorPlatformAdmin : self::ActorTenantUser,
            'original_actor_id' => $session instanceof SupportSession ? $session->platform_admin_id : $session->impersonator_user_id,
            'original_actor_name' => $active->actorName(),
            'original_actor_email' => $active->actorEmail(),
        ];
    }

    /**
     * One id per HTTP request, shared by every record the request writes. Outside an
     * HTTP request (console, queue) there is none, so unrelated work is never linked.
     */
    protected static function correlationId(): ?string
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($request === null || $request->route() === null) {
            return null;
        }

        if (! $request->attributes->has(self::CorrelationAttribute)) {
            $request->attributes->set(self::CorrelationAttribute, (string) Str::uuid());
        }

        return $request->attributes->get(self::CorrelationAttribute);
    }

    public function isSystemAction(): bool
    {
        return $this->actor_type === self::ActorSystem;
    }

    public function wasImpersonated(): bool
    {
        return $this->impersonation_kind !== null;
    }
}
