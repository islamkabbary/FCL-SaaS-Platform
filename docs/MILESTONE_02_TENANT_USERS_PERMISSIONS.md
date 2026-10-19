# Milestone 2 — Tenant Users & Permissions

This document describes what was **actually implemented and verified** in Milestone 2. It builds on [Milestone 1 — SaaS Foundation](MILESTONE_01_SAAS_FOUNDATION.md). Read that first for the tenancy architecture (one database per tenant, one subdomain per tenant, and separate platform and tenant authentication).

---

## 1. Overview

**Goal (met):** a Tenant Admin can securely manage employees, roles and permissions inside their own company database. Other tenants and the central platform stay isolated.

| Requirement | Status |
|---|---|
| Employee list with search and filters (status, role) | Done |
| Create employees (Default Branch `MAIN`, one-time temporary password, forced change) | Done |
| Edit employee details and roles | Done |
| Activate and deactivate employees; deactivation ends existing sessions (server-side) | Done |
| Cannot deactivate yourself; the last active Tenant Admin is protected | Done |
| `spatie/laravel-permission` v8 with tenant-specific roles and permissions | Done (8.3.0) |
| Role list, create, edit, permission assignment, role assignment | Done |
| Built-in Tenant Admin role (all permissions, locked) and Employee role (no admin permissions) | Done |
| Existing tenants and new tenants receive roles idempotently | Done (provisioning + `tenants:sync-authorization`) |
| Roles and permissions stored only in tenant databases | Done |
| Permission caches and in-memory state isolated per tenant | Done (custom tenancy bootstrapper) |
| Backend authorization and privilege-escalation protection | Done (policies + actions) |
| Navigation: Employees, Roles & Permissions | Done |

---

## 2. Employee Management Workflow

**Page:** `{subdomain}.localhost/employees`, a Livewire full-page component at `resources/views/pages/employees/⚡index.blade.php`.

| Action | Required permission | Extra rules (enforced in the backend) |
|---|---|---|
| Open the page / list | `employees.view` | — |
| Search (name or email), filter by status and role | `employees.view` | Filters are kept in the URL query string; 15 per page |
| Create employee | `employees.create` | Choosing roles also needs `roles.assign`; with no roles selected, the **Employee** role is assigned |
| Edit name and email | `employees.update` | Cannot edit a user who holds permissions you don't (e.g. a non-admin editing a Tenant Admin's email, which would enable account takeover) |
| Change roles | `roles.assign` | Not your own roles; not a user who outranks you; only roles whose permissions you hold; only a Tenant Admin can grant **Tenant Admin**; the last active Tenant Admin keeps the role |
| Deactivate | `employees.deactivate` | Not yourself; not a user who outranks you; never the last active Tenant Admin |
| Activate | `employees.deactivate` | Not a user who outranks you |

**Creating an employee** (`App\Actions\Employees\CreateEmployee`):
1. Authorizes `create`, validates the input (name, a unique email within the tenant, and roles that exist in *this* tenant database), and lowercases the email.
2. Assigns the employee to the **Default Branch (`MAIN`)**. Additional branches and transfers are out of scope.
3. Generates a temporary password with `Str::password(20)` and stores it bcrypt-hashed, with `must_change_password = true` and `is_active = true`.
4. Assigns the roles (see the escalation rules above).
5. The temporary password is **shown once** in a modal. It lives only in the Livewire component state until the modal closes (`forgetTemporaryPassword()`). It is never written to the database in plain text, never flashed to the session, never logged, and `CreatedEmployee` redacts it from dumps.
6. At first login the employee is forced to change it, using the Milestone 1 mechanism (`EnsurePasswordIsChanged`).

**Deactivating an employee** (`App\Actions\Employees\SetEmployeeActivation`):
- Sets `is_active = false` and `deactivated_at`, and **rotates the remember-me token**.
- **Deletes the employee's stored sessions** from the tenant `sessions` table.
- Runs inside a transaction that **locks the active Tenant Admin rows**, so two admins cannot deactivate each other at the same moment.
- Even without those deletions, **`EnsureUserIsActive`**, which is in the `tenant` middleware group and so also covers Livewire updates, logs a deactivated user out on their very next request. It invalidates the session and redirects to login with the message "Your account has been deactivated."
- **Login is refused** for deactivated accounts (`Fortify::authenticateUsing`). The "deactivated" message is shown only after a correct password, so it cannot be used to discover accounts.

---

## 3. Roles and Permissions

### Permission catalog (`App\Enums\TenantPermission`)
These are initial application permissions only; FCL Operations permissions will come in later milestones.

| Permission | Meaning |
|---|---|
| `employees.view` | View the employee list |
| `employees.create` | Create employees |
| `employees.update` | Edit employee details |
| `employees.deactivate` | Activate and deactivate employees |
| `roles.view` | View roles |
| `roles.manage` | Create, edit and delete roles |
| `roles.assign` | Assign roles to employees |

### Built-in roles (`App\Enums\TenantRole`)

| Role | Permissions | Protection |
|---|---|---|
| **Tenant Admin** | All permissions; re-synced on every authorization sync | Cannot be edited, renamed or deleted; only a Tenant Admin can grant it; the last active holder cannot lose it or be deactivated |
| **Employee** | None (created empty) | Cannot be renamed or deleted; its permissions *can* be edited (by someone holding them), and later syncs never reset them |

**Custom roles:** created and edited at `{subdomain}.localhost/roles` (`resources/views/pages/roles/⚡index.blade.php`) by users with `roles.manage`.
- Only catalog permissions can be used, and only those the actor holds.
- A role that holds permissions the actor lacks cannot be edited or deleted by that actor.
- Built-in role names are reserved.

**Guard:** every role and permission uses the **`web`** guard. `User` declares `protected string $guard_name = 'web'` explicitly, so the `platform` guard (Platform Admins) can never cause a guard mismatch. Platform Admins do not use roles at all.

### Privilege-escalation rules (`App\Actions\Roles\PrivilegeGuard`)
1. **Grant only what you hold:** permissions given to a role, and the roles you assign, must be a subset of your own permissions.
2. **No acting on superiors:** you cannot edit, re-role, deactivate or activate a user whose permissions exceed yours. A Tenant Admin outranks every non-admin.
3. **Tenant Admin is special:** only a Tenant Admin can grant the Tenant Admin role.
4. **No self-service:** you cannot change your own roles or deactivate yourself.

These rules are enforced in **policies** (`App\Policies\UserPolicy`, `App\Policies\RolePolicy`) and again inside the **actions**, so a crafted Livewire request bypasses nothing. Hidden buttons are a convenience, not the protection.

**Last-admin protection, by design:** only a Tenant Admin can act on a Tenant Admin, and nobody can act on themselves. So whenever an active admin could be demoted or deactivated, a second active admin necessarily exists. The explicit "last active Tenant Admin" checks remain as defense in depth.

---

## 4. Tenant Isolation

| Concern | How it is isolated |
|---|---|
| Role and permission tables | The Spatie migration lives in `database/migrations/tenant/` and runs per tenant. The central database has **no** `roles`, `permissions` or pivot tables (tested). |
| Employees | `User` uses the default connection, which is the tenant database during tenant requests. A user ID from another tenant cannot be found (404, tested). |
| Role assignment across tenants | Role IDs are validated with `Rule::exists(Role::class, 'id')` against the **current tenant database**, and the action reloads roles from it. A role ID that exists only in another tenant is rejected (tested). |
| Administration pages across tenants | Tenant sessions are host-bound and scoped (`ScopeSessions`); a replayed session gets 403 (tested). |
| Platform functionality | Unchanged from Milestone 1: central routes are bound to the central domain, and guards are separate. |
| Permission cache and in-memory state | See §5. |

---

## 5. Permission Caching

`PermissionRegistrar` (a singleton) keeps a **cache entry** and an **in-memory collection** of all permissions, each with its roles hydrated.

**Problem found and verified.** The stancl/tenancy integration guide only swaps `PermissionRegistrar::$cacheKey` per tenant. That does **not** clear the in-memory collection. In any process that switches tenants (tests, `tenants:*` commands, provisioning, future queue workers), tenant A's collection keeps being used for tenant B. The leak is concrete because Spatie checks role-based permissions against the **role models cached with each permission, matched by ID**, and role IDs collide across tenant databases. A regression test reproduces it:
- Tenant A's role #3 ("Viewer") holds `employees.view`.
- Tenant B's role #3 ("Plain") holds nothing.
- With only the key swap, B's "Plain" holder **was granted `employees.view`**.

**Solution:** `App\Tenancy\PermissionCacheBootstrapper`, registered in `config/tenancy.php` → `bootstrappers`.
- **On tenancy start:** it calls `initializeCache()`, which resets the store and clears all in-memory permissions, roles and wildcard indexes. It then sets the key `spatie.permission.cache.tenant.<tenant-uuid>`.
- **On tenancy end:** it calls `initializeCache()` again, which restores the central key and clears memory.

**Cache store:** `config/permission.php` → `'store' => env('PERMISSION_CACHE_STORE', 'array')`.
- The app's default cache store is the **central** `database` store. Using it would copy each tenant's authorization data into the central database, so it is deliberately not used (a test asserts no `spatie.permission` keys appear in the central cache table).
- The process-local `array` store is never shared. Because another process cannot invalidate it, the bootstrapper also forgets the tenant's entry on every tenant initialization.
- **Net effect:** permissions are loaded from the tenant database **once per request** (one query for permissions with roles) and are always current.
- Role and permission changes call Spatie's `forgetCachedPermissions()` automatically, under the current tenant key.

**Later (Redis):** switch `PERMISSION_CACHE_STORE` to a Redis store. Keys are already tenant-scoped. Then remove the per-initialization `forget` (it only runs for `array`) so the shared cache is reused across requests. Redis was not introduced in this milestone.

---

## 6. Changes to Provisioning and Existing Tenants

- **New tenants:** `ProvisionTenant` now calls `SyncTenantAuthorization` inside the same tenant transaction that creates the Default Branch and the Tenant Admin. A newly provisioned tenant therefore has the full permission catalog, both built-in roles, and an admin holding **Tenant Admin**.
- **`SyncTenantAuthorization`** (idempotent, tenant context only):
  1. Creates every catalog permission.
  2. Creates the Tenant Admin role and syncs all permissions to it.
  3. Creates the Employee role if it is missing, without touching its permissions.
  4. Assigns Tenant Admin to users flagged `is_tenant_admin` who lack the role.
- **Existing tenants** (provisioned under Milestone 1) are upgraded with:
  ```bash
  php artisan tenants:migrate              # adds the permission tables and the users.is_active / deactivated_at columns
  php artisan tenants:sync-authorization   # all ready tenants; or --tenant=acme
  ```
  Both commands are safe to re-run. The backfill and its idempotence, including keeping a customized Employee role, are tested.
- **`users.is_tenant_admin`** (from Milestone 1) is kept as a legacy marker of the initial admin and used only for the backfill. **Authorization is based on the Tenant Admin role** (`User::isTenantAdmin()`). The column was not dropped, to avoid destructive schema changes on existing tenant databases.
- The local development database had **no existing tenants** when this milestone was verified, so no development data had to be migrated.

---

## 7. Packages

| Package | Version | Notes |
|---|---|---|
| `spatie/laravel-permission` | **8.3.0** | Requires `illuminate/* ^12.0\|^13.0`, so it is compatible with Laravel 13.37. Teams are **not** enabled; Composer reported no advisories. |
| `spatie/laravel-package-tools` | 1.93.3 | Dependency |

No npm changes were made. `npm audit` reports 0 vulnerabilities.

---

## 8. Migrations

All new migrations are **tenant** migrations (`database/migrations/tenant`). No central migration changed.

| File | Contents |
|---|---|
| `2026_10_19_112854_create_permission_tables.php` | Spatie tables: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` (no teams) |
| `2026_10_19_112900_add_activation_columns_to_users_table.php` | `users.is_active` (boolean, default true, indexed) and `users.deactivated_at` |

---

## 9. New and Modified Files

**New**

| Path | Responsibility |
|---|---|
| `app/Enums/TenantPermission.php`, `app/Enums/TenantRole.php` | Permission catalog and built-in roles |
| `app/Actions/Tenancy/SyncTenantAuthorization.php` | Idempotent per-tenant roles and permissions sync and backfill |
| `app/Console/Commands/SyncTenantAuthorizationCommand.php` | `tenants:sync-authorization` |
| `app/Actions/Employees/CreateEmployee.php`, `CreatedEmployee.php` | Create an employee; one-time result with the password redacted |
| `app/Actions/Employees/UpdateEmployee.php`, `SyncEmployeeRoles.php` | Edit details; change roles with escalation and last-admin rules |
| `app/Actions/Employees/SetEmployeeActivation.php` | Activate or deactivate; delete sessions; rotate the remember token |
| `app/Actions/Roles/SaveRole.php`, `DeleteRole.php`, `PrivilegeGuard.php` | Role management and the escalation rules |
| `app/Policies/UserPolicy.php`, `app/Policies/RolePolicy.php` | Employee and role authorization |
| `app/Http/Middleware/EnsureUserIsActive.php` | Ends a deactivated user's session on every tenant request |
| `app/Tenancy/PermissionCacheBootstrapper.php` | Tenant-scoped Spatie cache key and in-memory reset |
| `config/permission.php` | Spatie configuration (cache store `array`, documented) |
| `resources/views/pages/employees/⚡index.blade.php`, `resources/views/pages/roles/⚡index.blade.php` | Administration UI (Livewire + Flux) |
| `tests/Feature/Tenancy/EmployeeManagementTest.php`, `EmployeeDeactivationTest.php`, `RoleManagementTest.php`, `TenantAuthorizationIsolationTest.php` | Milestone 2 tests |

**Modified**

| Path | Change |
|---|---|
| `app/Models/User.php` | `HasRoles`, explicit `web` guard, `is_active` default and casts, `active()` scope, `isTenantAdmin()`, `isLastActiveTenantAdmin()` |
| `app/Actions/Tenancy/ProvisionTenant.php` | Runs `SyncTenantAuthorization` during provisioning |
| `app/Providers/AppServiceProvider.php` | Registers `RolePolicy` for Spatie's `Role` model |
| `app/Providers/FortifyServiceProvider.php` | `authenticateUsing`, which refuses deactivated accounts |
| `bootstrap/app.php` | `EnsureUserIsActive` in the `tenant` group, prioritized after `ScopeSessions` and before `auth` |
| `config/tenancy.php` | Registers `PermissionCacheBootstrapper` |
| `routes/tenant.php` | `employees` and `roles` routes behind `can:viewAny` |
| `resources/views/layouts/app/sidebar.blade.php` | "Administration" navigation, shown per permission |
| `database/factories/UserFactory.php` | `inactive()` state |
| `tests/Pest.php` | `tenantAdminUser()` and `userWithPermissions()` helpers |
| `tests/Feature/Tenancy/TenantIdentificationTest.php` | Middleware-order test includes `EnsureUserIsActive` |
| `composer.json`, `composer.lock` | `spatie/laravel-permission` |

---

## 10. Tests and Results

**Command:** `composer test` (config clear, `pint --test`, PHPStan level 7, `php artisan test`).

**Result:** **passed (exit code 0).**
- PHPStan: **0 errors**. Pint: clean.
- Tests: **108 total: 107 passed, 1 skipped, 381 assertions** (~50–60s). The skipped test is the Starter Kit 2FA test, since 2FA is disabled, as in Milestone 1.
- `npm run build` succeeds, and `npm audit` reports 0 vulnerabilities.
- After every run, only `fcl_central` and `fcl_central_testing` remain; test tenant databases are dropped.

| File | Tests | Covers (requirement #) |
|---|---|---|
| `Tenancy/EmployeeManagementTest` | 11 | Create with one-time password, in `MAIN`, Employee role, password not logged (1, 2); create with roles; validation; edit details and roles; deactivate and reactivate (3); search and filters; list shows branch and status; no self-deactivation or self-role change; last active admin protected (8); two admins; employees without permission get 403 page and actions (5) |
| `Tenancy/EmployeeDeactivationTest` | 5 | Active session ends on the next request (4); stored sessions deleted and remember token rotated; login refused; wrong password reveals nothing; reactivation restores login |
| `Tenancy/RoleManagementTest` | 10 | Create, edit and delete roles; validation and reserved names; role grants access (6); Tenant Admin role locked; Employee role rules; non-holders forbidden (5); role managers cannot grant unheld permissions; assigners cannot escalate themselves or others (7); non-admins cannot edit, demote or take over a Tenant Admin; admin can grant Tenant Admin |
| `Tenancy/TenantAuthorizationIsolationTest` | 8 | Tables only in tenant DBs (10); provisioning seeds catalog and roles; backfill of an existing tenant, idempotent and keeping customizations; tenant sees only its own employees, 404 for foreign IDs (9); foreign role IDs rejected (10); replayed session 403; **permission cache and in-memory isolation with colliding role IDs** (11); no permission data in the central cache |
| Milestone 1 tests | 74 | All still pass (12) |

**Regression probes:**
- With the bootstrapper reduced to the documented key swap, the cache-isolation test **fails**: a tenant-B user is wrongly granted a permission. With the real bootstrapper it passes.
- The middleware-order test caught a real ordering mistake during development (see §13).

**Real-server smoke test.** A built-in PHP server ran against the disposable testing databases, with real cookies and database sessions; the smoke tenant was dropped afterwards.
- The admin sees Employees (with Main Branch) and Roles & Permissions, plus the navigation.
- An employee gets no admin navigation, and both admin pages return **403**.
- Deactivation deleted the employee's stored session (1 → 0). The employee's existing cookie then led to the **login redirect**, and logging in showed **"This account has been deactivated."**
- The admin was unaffected, and the server log showed no errors.

**Browser:** no browser-automation tool was available in this environment. The modal-based Livewire interactions are covered by Livewire feature tests, and a manual browser pass is listed in §12.

---

## 11. Security Considerations

- **Authorization is in the backend:**
  - Route `can:viewAny` middleware, which Livewire re-applies on update requests.
  - `authorize()` in each component action.
  - Policies, plus re-checks inside every action.
- **Escalation prevention:** the subset rule, the no-superiors rule, Tenant-Admin-only grants, and no self-service (§3).
- **Concurrency:** deactivation and role removal lock the active Tenant Admin rows, so two admins cannot remove each other at once.
- **Account takeover:** non-admins cannot change a Tenant Admin's email, which would otherwise enable a password reset to an attacker's address.
- **Deactivation:** enforced per request by middleware, plus session deletion and remember-token rotation; login is also refused.
- **Credentials:** temporary passwords are shown once, hashed at rest, never logged or put in the session, and redacted from dumps.
- **Tenant isolation:** roles, permissions and users live in tenant databases; caches are per tenant, with no central copy; foreign IDs fail validation or return 404.
- **Guards:** roles and permissions use only `web`; Platform Admins (`platform`) are unaffected.

---

## 12. Local Manual Verification

**Prerequisites:** the Milestone 1 setup ([§18 there](MILESTONE_01_SAAS_FOUNDATION.md#18-local-setup-and-manual-verification)), then:

```bash
composer install
php artisan migrate                      # central (no changes in this milestone)
php artisan tenants:migrate              # only if tenants existed before this milestone
php artisan tenants:sync-authorization   # idem
npm run build && php artisan serve
```

**Walkthrough:**
1. As Platform Admin (`http://localhost:8000/admin/login`), create tenant `acme` and note the temporary password.
2. Log in at `http://acme.localhost:8000/login` and change the temporary password. The sidebar now shows **Administration → Employees, Roles & Permissions**.
3. **Employees → New employee:** create "Erin", leaving roles empty. Copy the one-time password; closing the modal discards it. The list shows Erin with **Main Branch**, role **Employee** and status **Active**.
4. **Roles & Permissions → New role:** create "HR Viewer" with `employees.view`. Edit Erin and give her "HR Viewer".
5. In a private window, log in as Erin at `http://acme.localhost:8000/login`, change the password, and check:
   - **Employees** is visible but has no create, edit or deactivate buttons.
   - `/roles` returns **403**.
6. Back as admin, **deactivate** Erin. Refresh Erin's window: she is sent to the login page with "Your account has been deactivated", and logging in again is refused.
7. Try to deactivate yourself: no button is shown. If the action is forced, it is refused.
8. The Tenant Admin role shows no Edit or Delete action. The Employee role can be edited (permissions only) but not deleted.
9. Create a second tenant `globex`: its Employees and Roles pages show none of Acme's data.

---

## 13. Issues Found During Development

1. **The documented stancl + Spatie integration leaks permissions across tenants within one process** (§5). Fixed with `PermissionCacheBootstrapper`; a regression test reproduces the leak.
2. **Middleware priority:** Laravel applies `appendToPriorityList` entries before `prependToPriorityList` entries. An "append after `ScopeSessions`" rule therefore put `EnsureUserIsActive` at the end of the list, after `auth`. The middleware-order test caught it, and it now uses an ordered prepend before `AuthenticatesRequests`.
3. **Factory-built users lacked `is_active` in memory.** Database defaults don't populate new model instances, so the new middleware treated `actingAs()` users as inactive. Fixed with a model default (`$attributes = ['is_active' => true]`).
4. **Email case:** the `lowercase` validation rule rejected `NEW@acme.test` instead of normalizing it. Emails are now lowercased before validation.
5. **PHPStan type loss:** stancl's `Tenant::newCollection()` returns an untyped `TenantCollection`. The sync command iterates with `cursor()` to keep the `Tenant` type, with no suppression.

---

## 14. Known Limitations

- **Employees always join `MAIN`.** There is no branch choice and no transfers (out of scope).
- **No "reset temporary password" action:** an employee who loses the one-time password needs the standard "Forgot password" flow (mail is logged locally) or a new account.
- **Permissions are loaded once per request** from the tenant database (array cache). That is fine at this scale; Redis would allow cross-request caching (§5).
- **`is_tenant_admin`** remains as a legacy column (§6).
- **Deleting a custom role** immediately removes its permissions from its holders; there is no confirmation beyond the browser dialog, and no audit trail yet.
- **No real-browser automation** was run in this environment (§10).

---

## 15. Deferred to Future Milestones

- Branch management, multi-branch access, and employee transfers
- FCL Operations and their permission catalog
- Subscription billing, paid seats and user limits, prorated billing
- User impersonation
- Platform-wide employee management
- Email invitations
- Approval workflows and advanced audit dashboards
- Redis-backed permission cache and `CacheTenancyBootstrapper`
