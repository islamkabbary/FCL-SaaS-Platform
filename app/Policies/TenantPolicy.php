<?php

namespace App\Policies;

use App\Models\PlatformAdmin;
use App\Models\Tenant;

/**
 * Tenant management on the central platform.
 *
 * Every Platform Admin may currently manage every tenant; the PlatformAdmin type hint
 * denies any other identity (such as a tenant user). Lifecycle rules (which tenant may
 * be suspended or reactivated) are enforced by the actions, not here.
 */
class TenantPolicy
{
    public function viewAny(PlatformAdmin $admin): bool
    {
        return true;
    }

    public function view(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function create(PlatformAdmin $admin): bool
    {
        return true;
    }

    public function update(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function suspend(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function reactivate(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }
}
