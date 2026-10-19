<?php

namespace App\Enums;

/**
 * Application permissions available inside a tenant (company).
 *
 * Employee and role management, and the company's view of its subscription;
 * FCL Operations permissions will be added in later milestones.
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
    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';

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
            self::BillingView => __('View the subscription, seats and billing history'),
            self::BillingManage => __('Request seat changes'),
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
