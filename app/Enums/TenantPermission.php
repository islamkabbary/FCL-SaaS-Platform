<?php

namespace App\Enums;

/**
 * Application permissions available inside a tenant (company).
 *
 * Every name is "{resource}.{action}". The action is one of the four basic operations
 * (show, create, edit, delete), or one of the few special actions listed in
 * SpecialActions that no basic operation describes. A basic operation exists only for an
 * operation the application really has: there is no "delete" for employees or parties.
 *
 * The Super Admin role is exempt from every check (User::hasPermissionTo()) and holds no
 * permission rows. Everyone else gets permissions only through roles a Super Admin
 * composes. None is available during an impersonation session, except the reviewed
 * read permissions of RestrictionPolicy.
 */
enum TenantPermission: string
{
    case EmployeesShow = 'employees.show';
    case EmployeesCreate = 'employees.create';
    case EmployeesEdit = 'employees.edit';
    case EmployeesActivate = 'employees.activate';
    case RolesShow = 'roles.show';
    case RolesCreate = 'roles.create';
    case RolesEdit = 'roles.edit';
    case RolesDelete = 'roles.delete';
    case RolesAssign = 'roles.assign';
    case BillingShow = 'billing.show';
    case BillingSeatRequestsCreate = 'billing.seat-requests.create';
    case BillingSeatRequestsDelete = 'billing.seat-requests.delete';
    case AuditShow = 'audit.show';
    case FclJobsShow = 'fcl.jobs.show';
    case FclJobsShowAllBranches = 'fcl.jobs.show-all-branches';
    case FclJobsCreate = 'fcl.jobs.create';
    case FclJobsEdit = 'fcl.jobs.edit';
    case FclJobsCancel = 'fcl.jobs.cancel';
    case FclAciEdit = 'fcl.aci.edit';
    case FclPartiesShow = 'fcl.parties.show';
    case FclPartiesCreate = 'fcl.parties.create';
    case FclPartiesEdit = 'fcl.parties.edit';

    /**
     * The four basic operations, in the order the role editor shows them.
     *
     * @var list<string>
     */
    public const BasicActions = ['show', 'create', 'edit', 'delete'];

    /**
     * Actions that no basic operation describes. Adding one is an owner decision.
     *
     * @var list<string>
     */
    public const SpecialActions = ['activate', 'assign', 'show-all-branches', 'cancel'];

    /**
     * Get the human readable label for the permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::EmployeesShow => __('View employees'),
            self::EmployeesCreate => __('Create employees'),
            self::EmployeesEdit => __('Edit employees'),
            self::EmployeesActivate => __('Activate and deactivate employees'),
            self::RolesShow => __('View roles'),
            self::RolesCreate => __('Create roles'),
            self::RolesEdit => __('Edit roles'),
            self::RolesDelete => __('Delete roles'),
            self::RolesAssign => __('Assign roles to employees'),
            self::BillingShow => __('View the subscription, seats and billing history'),
            self::BillingSeatRequestsCreate => __('Request seat changes'),
            self::BillingSeatRequestsDelete => __('Withdraw pending seat requests'),
            self::AuditShow => __('View the company audit log'),
            self::FclJobsShow => __('FCL: view import jobs of their own branch'),
            self::FclJobsShowAllBranches => __('FCL: view import jobs of every branch'),
            self::FclJobsCreate => __('FCL: open import jobs'),
            self::FclJobsEdit => __('FCL: edit import jobs'),
            self::FclJobsCancel => __('FCL: cancel import jobs'),
            self::FclAciEdit => __('FCL: correct the ACID of an import job'),
            self::FclPartiesShow => __('FCL: view parties (customers, agents, carriers)'),
            self::FclPartiesCreate => __('FCL: create parties'),
            self::FclPartiesEdit => __('FCL: edit parties'),
        };
    }

    /**
     * The resource part of the name, e.g. "fcl.parties" for "fcl.parties.edit".
     */
    public function resource(): string
    {
        return substr($this->value, 0, (int) strrpos($this->value, '.'));
    }

    /**
     * The action part of the name, e.g. "edit" for "fcl.parties.edit".
     */
    public function action(): string
    {
        return substr($this->value, (int) strrpos($this->value, '.') + 1);
    }

    /**
     * Whether the action is one of the four basic operations.
     */
    public function isBasic(): bool
    {
        return in_array($this->action(), self::BasicActions, true);
    }

    /**
     * The human readable name of a resource, for the role editor.
     */
    public static function resourceLabel(string $resource): string
    {
        return match ($resource) {
            'employees' => __('Employees'),
            'roles' => __('Roles'),
            'billing' => __('Subscription & billing'),
            'billing.seat-requests' => __('Seat requests'),
            'audit' => __('Audit log'),
            'fcl.jobs' => __('FCL: import jobs'),
            'fcl.aci' => __('FCL: ACID'),
            'fcl.parties' => __('FCL: parties'),
            default => $resource,
        };
    }

    /**
     * The permissions grouped by resource, in catalog order.
     *
     * @return array<string, list<self>>
     */
    public static function byResource(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->resource()][] = $permission;
        }

        return $groups;
    }

    /**
     * Permissions renamed since they were first deployed: old name => new name(s). The sync
     * gives every holder of an old name each of its new names, so a rename or a split keeps
     * who holds it. Names removed from the catalog without an entry here are deleted with
     * their grants.
     *
     * @return array<string, list<string>>
     */
    public static function renamed(): array
    {
        return [
            'employees.view' => [self::EmployeesShow->value],
            'employees.update' => [self::EmployeesEdit->value],
            'employees.deactivate' => [self::EmployeesActivate->value],
            'roles.view' => [self::RolesShow->value],
            'roles.manage' => [self::RolesCreate->value, self::RolesEdit->value, self::RolesDelete->value],
            'billing.view' => [self::BillingShow->value],
            'billing.manage' => [self::BillingSeatRequestsCreate->value, self::BillingSeatRequestsDelete->value],
            'audit.view' => [self::AuditShow->value],
            'fcl.jobs.view' => [self::FclJobsShow->value],
            'fcl.jobs.view-all-branches' => [self::FclJobsShowAllBranches->value],
            'fcl.jobs.update' => [self::FclJobsEdit->value],
            'fcl.aci.correct' => [self::FclAciEdit->value],
            'fcl.parties.view' => [self::FclPartiesShow->value],
            'fcl.parties.manage' => [self::FclPartiesCreate->value, self::FclPartiesEdit->value],
        ];
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
