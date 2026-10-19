<?php

namespace App\Billing;

use App\Models\Tenant;
use App\Models\User;

/**
 * Counts a tenant's active users (the seats in use) from the central side.
 *
 * Reads the tenant's own database; nothing is copied into the central database.
 * The count is a point-in-time value and is not covered by any central transaction.
 */
class ActiveUsers
{
    public function count(Tenant $tenant): int
    {
        return (int) $tenant->run(fn (): int => User::query()->active()->count());
    }
}
