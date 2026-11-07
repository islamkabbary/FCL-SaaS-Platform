# Milestone 09: Permissions by Basic Operation and the Super Admin Role

Date: 2026-11-07

This milestone renames every tenant permission to one of four basic operations and splits the `manage` permissions. It also turns the company owner's role into **Super Admin**, which is exempt from permission checks.

The owner confirmed these rules on 2026-11-07. They are recorded as BD-D05, which supersedes BD-D04, and BD-SEC06 in `docs/fcl/BUSINESS_DECISIONS.md`.

## 1. Owner decisions

| Topic | Decision |
|---|---|
| Naming | `{resource}.{action}`, where the action is `show`, `create`, `edit` or `delete`. There is no `manage`. |
| Special actions | Only four: `employees.activate`, `roles.assign`, `fcl.jobs.show-all-branches` and `fcl.jobs.cancel`. Adding one is an owner decision. |
| `delete` | Only where the operation exists. There is no `employees.delete` and no `fcl.parties.delete`, because employees and parties are deactivated, not deleted. |
| Super Admin | The company owner's role, formerly Tenant Admin. It is exempt from every permission check. Several are allowed, and only a Super Admin can grant the role. |
| Built-in roles | The code may rely only on the roles shipped with every tenant (`TenantRole`): Super Admin and Employee. Roles a Super Admin composes are unknown to the code, so authorization always checks permissions. |
| Automatic `show` | Not added. A role can hold `create` without `show`; whoever composes the role is responsible for that. |

## 2. The permission catalog (22 permissions)

| Section | show | create | edit | delete | Special |
|---|---|---|---|---|---|
| `employees` | ✓ | ✓ | ✓ | — | `employees.activate` (activate and deactivate) |
| `roles` | ✓ | ✓ | ✓ | ✓ | `roles.assign` |
| `billing` | ✓ | — | — | — | — |
| `billing.seat-requests` | — | ✓ (request an increase or a reduction) | — | ✓ (withdraw a pending request) | — |
| `audit` | ✓ | — | — | — | — |
| `fcl.jobs` | ✓ (own branch) | ✓ | ✓ | — | `fcl.jobs.show-all-branches`, `fcl.jobs.cancel` |
| `fcl.aci` | — | — | ✓ | — | — |
| `fcl.parties` | ✓ | ✓ | ✓ | — | — |

`fcl.jobs.*` and `fcl.aci.edit` are still checked nowhere, because the job feature is not built yet.

### Mapping from the old names

`TenantPermission::renamed()` now maps one old name to **one or more** new names. `tenants:sync-authorization` gives every holder of an old name, whether a role or a direct grant, each of its new names, then deletes the old name. Existing roles therefore keep what they could do.

| Old | New |
|---|---|
| `employees.view`, `employees.update`, `employees.deactivate` | `employees.show`, `employees.edit`, `employees.activate` |
| `roles.view` | `roles.show` |
| `roles.manage` | `roles.create`, `roles.edit`, `roles.delete` |
| `billing.view` | `billing.show` |
| `billing.manage` | `billing.seat-requests.create`, `billing.seat-requests.delete` |
| `audit.view` | `audit.show` |
| `fcl.jobs.view`, `fcl.jobs.view-all-branches`, `fcl.jobs.update` | `fcl.jobs.show`, `fcl.jobs.show-all-branches`, `fcl.jobs.edit` |
| `fcl.aci.correct` | `fcl.aci.edit` |
| `fcl.parties.view` | `fcl.parties.show` |
| `fcl.parties.manage` | `fcl.parties.create`, `fcl.parties.edit` |

### Where each split permission is checked

- **Roles:** `RolePolicy::create` checks `roles.create`, `update` checks `roles.edit`, and `delete` checks `roles.delete`.
- **Parties:** `PartyPolicy::create` checks `fcl.parties.create`, and `update` checks `fcl.parties.edit`.
- **Seat requests:** `RequestSeatChange::handle` checks `billing.seat-requests.create`, and `cancel` checks `billing.seat-requests.delete`. The subscription page shows the request buttons and the cancel button separately.
- **Impersonation:** the allowlist in `RestrictionPolicy` now reads `employees.show`, `roles.show` and `billing.show`.

## 3. Super Admin

**Where the exemption lives.** It is in the permission check itself, not in a `Gate::before`. `User::hasPermissionTo()` returns true for a Super Admin, **for catalog names only**. `checkPermissionTo()`, `can('employees.edit')` and spatie's Gate hook all go through it.

A `Gate::before` was rejected because it would also have skipped the policy rules. The catalog-only condition matters for a related reason: spatie's Gate hook also asks about policy abilities such as `update` or `deactivate`, so answering yes there would have skipped the policy as well.

**What the Super Admin is still bound by:**
- Policy rules: you cannot deactivate yourself or change your own roles, the last active Super Admin cannot be deactivated or demoted, and the built-in roles cannot be edited or deleted.
- Impersonation restrictions. The impersonation hook runs before any permission check, so a support session on a Super Admin stays read-only.
- Subscription status and suspension.
- Seats: a Super Admin uses one like every active user.

**The role itself:**
- It holds no permission rows; the sync removes them. A permission added later works for the Super Admin without a sync.
- `PrivilegeGuard::permissionNamesOf()` returns the whole catalog for a Super Admin. This keeps the escalation rules working: who outranks whom, who can grant what, and whom a Super Admin may view the application as.
- The sync renames the existing `Tenant Admin` role to `Super Admin` in place, so its holders keep it. `Tenant Admin` stays a reserved name.
- Code renames: `isTenantAdmin()` → `isSuperAdmin()`, `isLastActiveTenantAdmin()` → `isLastActiveSuperAdmin()`, `activeTenantAdminCount()` → `activeSuperAdminCount()`, and `UserFactory::tenantAdmin()` → `superAdmin()`. The `is_tenant_admin` column keeps its name: it only marks the first owner, and only the sync reads it.
- Every "Tenant Admin" text is now "Super Admin", or "المدير العام" in Arabic.

## 4. Built-in roles

`TenantRole` lists the roles shipped with every tenant, which are the only roles the code may name. `defaultPermissions()` gives each one its initial permissions. They apply only when the sync first creates the role, so a Super Admin's later changes are kept.

To ship another built-in role, add a case and its default permissions. Built-in roles cannot be renamed or deleted, but a Super Admin can change the Employee role's permissions. No new built-in role was added, because Q-P07 is still open.

## 5. UI

- **Role editor.** A permission matrix replaces the flat list of checkboxes. It has one row per section, and columns for Show, Create, Edit and Delete, plus a column for the special actions. A permission the user does not hold stays disabled. The matrix scrolls inside its own container on a phone and works in RTL.
- **Roles list.** The Super Admin role is marked "All permissions (exempt from every check)" and has no edit or delete button.
- **Employee form.** Only roles the current user may give are offered, together with the roles the edited employee already holds. Super Admin and any role with permissions the user lacks are hidden. The server still checks every role on save.

## 6. Role audit

`RoleAuditAction` adds `role.created`, `role.updated` and `role.deleted`. `SaveRole` and `DeleteRole` write them to `TenantAuditLog` in the same transaction, with the role's name and sorted permissions before and after. A deletion also records how many employees held the role.

Saving a role without changing it writes nothing. This closes the "role changes are not audited" limitation of Milestone 2.

## 7. Files

**Added:**
- `app/Enums/RoleAuditAction.php`
- `tests/Feature/Tenancy/SuperAdminTest.php`
- `tests/Feature/Tenancy/PermissionCatalogTest.php`

**Changed:**
- `TenantPermission`: the catalog, `resource()`, `action()`, `byResource()`, and a one-to-many `renamed()`.
- `TenantRole`: `SuperAdmin`, `defaultPermissions()` and the legacy reserved name.
- `SyncTenantAuthorization`: split renames, the role rename, and default permissions.
- `User`: the exemption and the renames.
- `PrivilegeGuard` and `EmployeeImpersonationRules`.
- `RolePolicy`, `UserPolicy`, `PartyPolicy` and `TenantAuditLogPolicy`.
- `RequestSeatChange` and `RestrictionPolicy`.
- `SaveRole` and `DeleteRole`.
- The roles, employees and subscription pages, the sidebar and the settings delete modal.
- `routes/tenant.php`, `MainAccountsSeeder` and `UserFactory`.
- `lang/ar.json`.
- The tests, where `tenantAdminUser()` is now `superAdminUser()`.

**Docs and rules:**
- `docs/fcl/BUSINESS_DECISIONS.md` (BD-D05, BD-SEC06).
- `docs/fcl/PROJECT_CONTEXT.md` §4.6.
- `.ai/rules/security-errors.md`, which gains the naming rule and the "permissions, not composed roles" rule.

## 8. Tests

These folders and files passed: `tests/Feature/Tenancy`, `tests/Feature/Fcl`, `tests/Feature/Hardening`, `tests/Feature/Impersonation`, `SecurityHardeningTest`, `MainAccountsSeederTest`, `LocaleTest`, `Billing/SeatRequestTest`, `Billing/SubscriptionLifecycleTest` and `TranslationCompletenessTest`.

The new tests cover:
- the Super Admin passing every catalog permission without rows, but nothing outside the catalog;
- the policy rules still binding the Super Admin;
- only a Super Admin granting the role, and the form hiding it;
- each split permission granting only its own operation;
- seat requests split from withdrawals;
- the role audit;
- the matrix in both languages;
- the naming rule;
- the sync of a tenant created before the split, run twice;
- the Tenant Admin role being renamed with its holders.

## 9. Deployment

- `php artisan tenants:sync-authorization`. There are no migrations. Existing roles keep their grants under the new names, and the Tenant Admin role becomes Super Admin.
- `npm run build`.

## 10. Known limitations

- A role can hold `create`, `edit` or `delete` without `show`. Its holders then cannot open the page. This is by owner decision.
- `fcl.jobs.*` and `fcl.aci.edit` are not enforced yet.
- No built-in role besides Employee exists yet (Q-P07).
- There is no screen for the company audit log yet. The role records are written, and `audit.show` guards them.

## 11. Manual verification

1. Sign in as the owner. The roles page marks Super Admin as having all permissions.
2. Create a role with Employees: Show and Create only, and give it to an employee from the employee form. Super Admin does not appear there for users who are not Super Admins.
3. Sign in as that employee. The employee can list and create employees, but cannot edit, deactivate or open the roles page.
4. Check the matrix in Arabic and English, and on a phone width.
