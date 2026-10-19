<?php

namespace App\Enums;

/**
 * Application permissions available inside a tenant (company).
 *
 * This is the initial catalog for employee and role management only; FCL
 * Operations permissions will be added in later milestones.
 */
enum TenantPermission: string
{
    case EmployeesView = 'employees.view';
    case EmployeesCreate = 'employees.create';
    case EmployeesUpdate = 'employees.update';
    case EmployeesDeactivate = 'employees.deactivate';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case RolesAssign = 'roles.assign';

    /**
     * Get the human readable label for the permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::EmployeesView => __('View employees'),
            self::EmployeesCreate => __('Create employees'),
            self::EmployeesUpdate => __('Edit employees'),
            self::EmployeesDeactivate => __('Activate and deactivate employees'),
            self::RolesView => __('View roles'),
            self::RolesManage => __('Create, edit and delete roles'),
            self::RolesAssign => __('Assign roles to employees'),
        };
    }

    /**
     * Get every permission name.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
