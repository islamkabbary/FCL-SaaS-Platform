<?php

namespace App\Policies\Fcl;

use App\Enums\TenantPermission;
use App\Models\Fcl\Party;
use App\Models\User;

/**
 * FCL parties of the current tenant. Parties are shared by every branch of the company,
 * so no branch scope applies. Party records only ever come from the current tenant
 * database. During an impersonation every ability here is refused before this policy
 * runs (RestrictionPolicy denies App\Models\Fcl resources, reads included).
 *
 * checkPermissionTo() denies when a permission row is missing (a company whose
 * authorization was not synced yet) instead of failing: viewAny runs on every page,
 * for the sidebar.
 */
class PartyPolicy
{
    /**
     * Only tenant users are ever granted anything here (never a Platform Admin).
     */
    public function before(mixed $user): ?bool
    {
        return $user instanceof User ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::FclPartiesView->value);
    }

    public function view(User $actor, Party $party): bool
    {
        return $actor->checkPermissionTo(TenantPermission::FclPartiesView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::FclPartiesManage->value);
    }

    public function update(User $actor, Party $party): bool
    {
        return $actor->checkPermissionTo(TenantPermission::FclPartiesManage->value);
    }
}
