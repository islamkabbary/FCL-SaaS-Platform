<?php

namespace App\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Makes spatie/laravel-permission tenant-aware.
 *
 * Besides the cache key recommended by the stancl/tenancy integration guide, the
 * PermissionRegistrar singleton keeps the loaded permission collection (and role
 * and wildcard indexes) in memory. Changing only the key would keep serving the
 * previous tenant's permissions in any process that switches tenants (tests,
 * tenants:* commands, provisioning, queue workers). This bootstrapper therefore
 * re-initializes the registrar on every tenant switch and when tenancy ends.
 */
class PermissionCacheBootstrapper implements TenancyBootstrapper
{
    public const CacheKeyPrefix = 'spatie.permission.cache.tenant.';

    public function __construct(protected PermissionRegistrar $registrar) {}

    public function bootstrap(Tenant $tenant): void
    {
        // Resets the key, the cache store and all in-memory permission state.
        $this->registrar->initializeCache();
        $this->registrar->cacheKey = self::CacheKeyPrefix.$tenant->getTenantKey();

        // A process-local store is never invalidated by other processes (e.g. a role
        // changed by another request), so it is refreshed on every tenant initialization.
        if (config('permission.cache.store') === 'array') {
            $this->registrar->getCacheRepository()->forget($this->registrar->cacheKey);
        }
    }

    public function revert(): void
    {
        // Back to the central cache key, with no tenant permissions left in memory.
        $this->registrar->initializeCache();
    }
}
