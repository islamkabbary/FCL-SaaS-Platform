<?php

namespace App\Enums;

/**
 * Application permissions available inside a tenant (company).
 *
 * Employee and role management, the company's view of its subscription, the company
 * audit log, and FCL Operations. FCL permissions are granted to the Tenant Admin role by
 * tenants:sync-authorization and to nobody else until a Tenant Admin grants them through
 * a role. None of them is available during an impersonation session (RestrictionPolicy).
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
    case AuditView = 'audit.view';
    case FclJobsView = 'fcl.jobs.view';
    case FclJobsViewAllBranches = 'fcl.jobs.view-all-branches';
    case FclJobsCreate = 'fcl.jobs.create';
    case FclJobsUpdate = 'fcl.jobs.update';
    case FclJobsCancel = 'fcl.jobs.cancel';
    case FclAciCorrect = 'fcl.aci.correct';
    case FclPartiesView = 'fcl.parties.view';
    case FclPartiesManage = 'fcl.parties.manage';

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
            self::AuditView => __('View the company audit log'),
            self::FclJobsView => __('FCL: view import jobs of their own branch'),
            self::FclJobsViewAllBranches => __('FCL: view import jobs of every branch'),
            self::FclJobsCreate => __('FCL: open import jobs'),
            self::FclJobsUpdate => __('FCL: edit import jobs'),
            self::FclJobsCancel => __('FCL: cancel import jobs'),
            self::FclAciCorrect => __('FCL: correct the ACID of an import job'),
            self::FclPartiesView => __('FCL: view parties (customers, agents, carriers)'),
            self::FclPartiesManage => __('FCL: create and edit parties'),
        };
    }

    /**
     * Permissions renamed since they were first deployed: old name => new name. The sync
     * moves every grant of an old name to the new one, so a rename keeps who holds it.
     * Names removed from the catalog without an entry here are deleted with their grants.
     *
     * @return array<string, string>
     */
    public static function renamed(): array
    {
        return [];
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
