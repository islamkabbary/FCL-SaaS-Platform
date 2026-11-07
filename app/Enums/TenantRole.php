<?php

namespace App\Enums;

/**
 * The built-in roles created in every tenant, like a seeder: the only roles the code may
 * rely on. Roles a Super Admin composes later are unknown to the code, so authorization
 * always checks permissions, never a custom role.
 *
 * Built-in roles cannot be renamed or deleted. To ship another built-in role, add a case
 * with its defaultPermissions().
 */
enum TenantRole: string
{
    case SuperAdmin = 'Super Admin';
    case Employee = 'Employee';

    /**
     * The name the Super Admin role had before it was renamed. It stays reserved, so no
     * custom role can pass for the built-in one.
     */
    public const LegacySuperAdminName = 'Tenant Admin';

    /**
     * The permissions a built-in role starts with when the sync creates it. They are never
     * reapplied, so a Super Admin's later changes to the role are kept. The Super Admin
     * role holds none: it is exempt from every permission check.
     *
     * @return list<TenantPermission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin, self::Employee => [],
        };
    }

    /**
     * The name of a role to display: built-in roles are translated, custom roles stay as entered.
     */
    public static function displayName(string $roleName): string
    {
        return match (self::tryFrom($roleName)) {
            self::SuperAdmin => __('Super Admin'),
            self::Employee => __('Employee'),
            null => $roleName,
        };
    }

    /**
     * Determine whether the given role name belongs to a built-in role.
     */
    public static function isSystem(string $roleName): bool
    {
        return self::tryFrom($roleName) !== null;
    }

    /**
     * A role name as it is stored: trimmed, with inner whitespace collapsed, so look-alike
     * names such as " Super Admin" or "Super  Admin" cannot be created.
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
        $reserved = [...array_map(fn (self $role): string => $role->value, self::cases()), self::LegacySuperAdminName];

        foreach ($reserved as $name) {
            if (mb_strtolower($name) === $normalized) {
                return true;
            }
        }

        return false;
    }
}
