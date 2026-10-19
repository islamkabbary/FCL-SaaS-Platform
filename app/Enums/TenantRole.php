<?php

namespace App\Enums;

/**
 * Built-in (system) roles created in every tenant. They cannot be renamed or deleted.
 */
enum TenantRole: string
{
    case TenantAdmin = 'Tenant Admin';
    case Employee = 'Employee';

    /**
     * Determine whether the given role name belongs to a built-in role.
     */
    public static function isSystem(string $roleName): bool
    {
        return self::tryFrom($roleName) !== null;
    }
}
