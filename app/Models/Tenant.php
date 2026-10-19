<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A freight forwarding company. Stored in the central database; its own data
 * lives in a dedicated tenant database.
 *
 * Attributes that are not custom columns are stored in the "data" JSON column.
 *
 * @property string $id
 * @property string $name
 * @property TenantStatus $status
 * @property string|null $provisioning_error
 * @property Carbon|null $provisioned_at
 * @property string|null $initial_admin_name
 * @property string|null $initial_admin_email
 * @property-read Collection<int, Domain> $domains
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
     * Determine whether the tenant finished provisioning and may be accessed.
     */
    public function isReady(): bool
    {
        return $this->status === TenantStatus::Ready;
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
