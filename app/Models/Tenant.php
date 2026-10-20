<?php

namespace App\Models;

use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantAccessStatus;
use App\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A freight forwarding company. Stored in the central database; its own data
 * lives in a dedicated tenant database.
 *
 * Two independent lifecycles: "status" tracks provisioning (pending → provisioning →
 * ready | failed), "suspended_at" tracks whether the platform allows access. Only a
 * ready, non-suspended tenant can be accessed. A third, independent concept is the
 * subscription (see subscriptionStatus()), which governs ordinary tenant functionality
 * and the seat limit but never changes "status" or "suspended_at".
 *
 * Attributes that are not custom columns are stored in the "data" JSON column.
 *
 * @property string $id
 * @property string $name
 * @property TenantStatus $status
 * @property string|null $provisioning_error
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property bool $subscription_required False only for tenants created before subscriptions existed and not transitioned yet.
 * @property string|null $initial_admin_name
 * @property string|null $initial_admin_email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Domain> $domains
 * @property-read Collection<int, PlatformAuditLog> $auditLogs
 * @property-read TenantSubscription|null $subscription
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Columns stored as real database columns instead of inside the "data" JSON column.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'status',
            'provisioning_error',
            'provisioned_at',
            'suspended_at',
            'suspension_reason',
            'subscription_required',
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'provisioned_at' => 'datetime',
            'suspended_at' => 'datetime',
            'subscription_required' => 'boolean',
        ];
    }

    /**
     * Get the domains (subdomains) that identify the tenant.
     *
     * Overrides the untyped relation from HasDomains to declare its type.
     *
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'tenant_id');
    }

    /**
     * Get the platform administration history of the tenant.
     *
     * @return HasMany<PlatformAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(PlatformAuditLog::class, 'tenant_id');
    }

    /**
     * Get the tenant's annual subscription, if one was activated.
     *
     * @return HasOne<TenantSubscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class, 'tenant_id');
    }

    /**
     * Whether a provisioning run stopped without recording a result (no progress for
     * ProvisionTenant::StaleAfterMinutes), so provisioning can be resumed.
     */
    public function isProvisioningStale(): bool
    {
        return $this->status === TenantStatus::Provisioning
            && $this->updated_at !== null
            && $this->updated_at->lessThan(now()->subMinutes(ProvisionTenant::StaleAfterMinutes));
    }

    /**
     * The subscription state on the given day (today by default), read from the central database.
     */
    public function subscriptionStatus(?CarbonImmutable $day = null): SubscriptionStatus
    {
        $subscription = TenantSubscription::query()->where('tenant_id', $this->getTenantKey())->first();

        if ($subscription === null) {
            // A newly created tenant requires a subscription unless it was created before they existed.
            return $this->subscription_required === false ? SubscriptionStatus::Legacy : SubscriptionStatus::None;
        }

        return $subscription->termOn($day) !== null ? SubscriptionStatus::Active : SubscriptionStatus::Expired;
    }

    /**
     * Maximum number of active users on the given day: null means unlimited (legacy
     * tenant), 0 means no active subscription, otherwise the current term's seats.
     */
    public function seatLimit(?CarbonImmutable $day = null): ?int
    {
        $subscription = TenantSubscription::query()->where('tenant_id', $this->getTenantKey())->first();

        if ($subscription === null) {
            return $this->subscription_required === false ? null : 0;
        }

        return $subscription->termOn($day)->seats ?? 0;
    }

    /**
     * The fewest seats of any term already confirmed to start after today (an early
     * renewal), or null when there is none.
     */
    public function confirmedLaterTermSeats(): ?int
    {
        $subscription = TenantSubscription::query()->where('tenant_id', $this->getTenantKey())->first();

        if ($subscription === null) {
            return null;
        }

        return SubscriptionTerm::query()
            ->where('tenant_subscription_id', $subscription->id)
            ->whereDate('starts_on', '>', CarbonImmutable::today()->toDateString())
            ->get(['seats'])
            ->reduce(fn (?int $carry, SubscriptionTerm $term): int => $carry === null ? $term->seats : min($carry, $term->seats));
    }

    /**
     * Search by company name or subdomain.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '\%_').'%';

        $query->where(fn (Builder $query) => $query
            ->where('name', 'like', $like)
            ->orWhereHas('domains', fn (Builder $query) => $query->where('domain', 'like', $like)));
    }

    /**
     * Limit to tenants with the given access status.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereAccessStatus(Builder $query, TenantAccessStatus $accessStatus): void
    {
        $accessStatus === TenantAccessStatus::Suspended
            ? $query->whereNotNull('suspended_at')
            : $query->whereNull('suspended_at');
    }

    /**
     * Determine whether the tenant finished provisioning.
     */
    public function isReady(): bool
    {
        return $this->status === TenantStatus::Ready;
    }

    /**
     * Determine whether the platform has suspended the tenant's access.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Get the tenant's access status.
     */
    public function accessStatus(): TenantAccessStatus
    {
        return $this->isSuspended() ? TenantAccessStatus::Suspended : TenantAccessStatus::Active;
    }

    /**
     * Determine whether the tenant's users may use the application.
     */
    public function isAccessible(): bool
    {
        return $this->isReady() && ! $this->isSuspended();
    }

    /**
     * Only a ready tenant that is currently active can be suspended.
     */
    public function canBeSuspended(): bool
    {
        return $this->isAccessible();
    }

    /**
     * Only a suspended tenant can be reactivated.
     */
    public function canBeReactivated(): bool
    {
        return $this->isSuspended();
    }

    /**
     * A short, display-safe summary of the last provisioning failure.
     *
     * Connection details and SQL that database exceptions append to their message are
     * cut off, and anything that looks like a credential is redacted.
     */
    public function provisioningFailureSummary(): ?string
    {
        if ($this->provisioning_error === null || $this->provisioning_error === '') {
            return null;
        }

        $summary = (string) preg_replace('/\s*\((?:Connection|SQL|Host):.*$/s', '', $this->provisioning_error);
        $summary = (string) preg_replace('/\b(password|passwd|pwd|secret|token|key)\b(\s*[=:]\s*)\S+/i', '$1$2[redacted]', $summary);

        return Str::limit(trim($summary), 300);
    }

    /**
     * Get the tenant's primary (subdomain) domain record.
     */
    public function primaryDomain(): ?Domain
    {
        return $this->domains->first();
    }

    /**
     * Build an absolute URL on the tenant's subdomain, keeping the scheme and port of APP_URL.
     */
    public function url(string $path = '/'): string
    {
        $appUrl = parse_url(config('app.url'));

        $scheme = $appUrl['scheme'] ?? 'http';
        $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';
        $host = $this->primaryDomain()?->domain.'.'.config('tenancy.central_domains')[0];

        return "{$scheme}://{$host}{$port}/".ltrim($path, '/');
    }
}
