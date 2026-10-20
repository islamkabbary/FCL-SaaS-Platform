<?php

namespace App\Exceptions;

use App\Actions\Tenancy\ProvisionTenant;
use App\Models\Tenant;
use Exception;

/**
 * Thrown when another run is already provisioning the tenant. Only one run may work on a
 * tenant at a time; a run that stopped without finishing can be taken over once it has
 * shown no progress for ProvisionTenant::StaleAfterMinutes.
 */
class TenantProvisioningInProgress extends Exception
{
    public function __construct(public readonly Tenant $tenant)
    {
        parent::__construct("Tenant [{$tenant->id}] is already being provisioned. If that run stopped, it can be resumed ".ProvisionTenant::StaleAfterMinutes.' minutes after it started.');
    }
}
