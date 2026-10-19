<?php

namespace App\Exceptions;

use App\Models\Tenant;
use RuntimeException;

/**
 * Thrown when a tenant access lifecycle action does not apply to the tenant's current state.
 */
class InvalidTenantTransition extends RuntimeException
{
    public static function cannotSuspend(Tenant $tenant): self
    {
        return new self($tenant->isSuspended()
            ? __('This tenant is already suspended.')
            : __('Only a ready tenant can be suspended. This tenant is :status.', ['status' => mb_strtolower($tenant->status->label())]));
    }

    public static function cannotReactivate(): self
    {
        return new self(__('This tenant is not suspended.'));
    }
}
