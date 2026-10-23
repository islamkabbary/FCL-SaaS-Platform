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

    /**
     * A role name as it is stored: trimmed, with inner whitespace collapsed, so look-alike
     * names such as " Tenant Admin" or "Tenant  Admin" cannot be created.
     */
    public static function normalizeName(string $roleName): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $roleName));
    }

    /**
     * Determine whether a name is, or looks like, a built-in role's name (ignoring case
     * and whitespace), so no custom role can be given it.
     */
    public static function isReservedName(string $roleName): bool
    {
        $normalized = mb_strtolower(self::normalizeName($roleName));

        foreach (self::cases() as $role) {
            if (mb_strtolower($role->value) === $normalized) {
                return true;
            }
        }

        return false;
    }
}
