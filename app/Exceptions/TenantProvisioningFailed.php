<?php

namespace App\Exceptions;

use App\Models\Tenant;
use Exception;

/**
 * Thrown when a tenant could not be fully provisioned.
 *
 * The original exception is intentionally not chained: the tenant record keeps
 * a summary in "provisioning_error" and the failure is logged without stack
 * frame arguments, so no sensitive values can leak through exception reports.
 */
class TenantProvisioningFailed extends Exception
{
    public function __construct(public readonly Tenant $tenant, string $reason)
    {
        parent::__construct("Provisioning of tenant [{$tenant->id}] failed: {$reason}");
    }
}
