# Milestone 3 — Central SaaS Platform Management

This document describes what was **actually implemented and verified** in Milestone 3. It builds on [Milestone 1 — SaaS Foundation](MILESTONE_01_SAAS_FOUNDATION.md) and [Milestone 2 — Tenant Users & Permissions](MILESTONE_02_TENANT_USERS_PERMISSIONS.md).

---

## 1. Milestone Overview

**Goal (met):** the Platform Admin can manage tenant companies and their access to the platform from the central application. That means viewing, searching and inspecting tenants, editing safe administrative details, suspending and reactivating access, and reviewing an audit trail. Subscriptions, billing, impersonation and FCL operations were **not** implemented.

| Requirement | Status |
|---|---|
| Dashboard with real tenant statistics | Done |
| Tenant list: search (name, subdomain), filters (provisioning, access), pagination | Done |
| Tenant details: identifiers, subdomain, both statuses, dates, database name, sanitized failure summary | Done |
| Edit safe administrative information | Done (company name only; see §2) |
| Suspend a ready tenant with a mandatory reason | Done |
| Reactivate a suspended tenant without re-provisioning | Done |
| Server-side enforcement, including existing sessions and Livewire requests | Done |
| Provisioning status kept separate from access status | Done |
| Read-only central audit trail, visible from tenant details | Done |
| Confirmation UI for sensitive actions | Done (dedicated confirmation pages) |

---

## 2. Implemented Functionality

All pages are on the central domain under `/admin`. They require the existing `platform` guard (Platform Admin login).

| Page | URL | Notes |
|---|---|---|
| Dashboard | `/admin` | The landing page after login. It shows six counts (total, ready & active, suspended, pending, provisioning, failed), each linking to the matching filtered list, plus the 8 most recent audit entries across all tenants. |
| Tenants | `/admin/tenants` | Search by company name or subdomain. Filter by provisioning status and access status. 15 per page. Unknown filter values are ignored. |
| Tenant details | `/admin/tenants/{id}` | Shows company name, initial Tenant Admin, subdomain URL, tenant ID, database name, provisioning status and date, access status, any suspension reason and date, and the sanitized failure summary with the recovery command. It also shows the latest 5 history entries. |
| Edit tenant | `/admin/tenants/{id}/edit` | Only the **company name** is editable. The subdomain is shown read-only. |
| Suspend | `/admin/tenants/{id}/suspend` | A confirmation page with the consequences explained and a required reason. |
| Reactivate | `/admin/tenants/{id}/reactivate` | A confirmation page with an optional note. |
| History | `/admin/tenants/{id}/history` | The complete, read-only audit trail of the tenant, 20 per page. |

**Why only the company name is editable:** the tenant record's other fields are identifiers (ID, subdomain, database name), lifecycle state, or provisioning inputs. `initial_admin_name` and `initial_admin_email` are only used to seed the first Tenant Admin, and changing them after provisioning would not change the real user in the tenant database. No speculative business fields were added.

**Creating tenants** works as in Milestone 1. Two changes:
- Each creation is now audited.
- A failed provisioning redirects to the new tenant details page, which shows the failure summary and the `tenants:provision` recovery command, instead of to the list.

---

## 3. Central Platform Architecture

- **Controllers, Blade and Flux, not Livewire, on the central domain.** Milestone 1 bound Livewire's update endpoint to the `tenant` middleware group (`TenancyServiceProvider::configureLivewireUpdateRoute`), so a Livewire component on the central domain would be refused. Making that endpoint serve both contexts would weaken the tenant-context guarantee. The central pages therefore follow the existing central pattern: controllers, Blade views and Flux components. Pagination uses Laravel's Tailwind paginator, because Flux's paginator needs Livewire.
- **Every query is central.** `Tenant` and `PlatformAuditLog` use the central connection (`CentralConnection`). No page opens a tenant database. The list, the details page and the dashboard use only the central `tenants`, `domains` and `platform_audit_logs` tables.
- **Authorization.**
  - The `auth:platform` middleware makes `platform` the default guard, so route `can:` middleware checks the Platform Admin against `App\Policies\TenantPolicy`.
  - The policy is auto-discovered. It currently allows every Platform Admin. Its `PlatformAdmin` type hint denies any other identity, and it is the single place to add platform roles later.
  - Form requests authorize again.
- **Actions.** `App\Actions\Platform\{SuspendTenant, ReactivateTenant, UpdateTenantDetails}` hold the business rules. Each one:
  1. opens a central transaction;
  2. locks the tenant row (`lockForUpdate`) and re-reads it;
  3. checks the transition;
  4. writes the change and its audit record together.

  Because each action re-reads and locks the tenant, a stale model or two concurrent requests cannot create an invalid state.

---

## 4. Tenant Lifecycle Model

A tenant has **two independent lifecycles**:

```
Provisioning (column "status", unchanged from Milestone 1)
  pending ──► provisioning ──► ready
                    │
                    └──► failed ──(php artisan tenants:provision)──► provisioning ...

Access (column "suspended_at", new)
  active ◄──── reactivate ────┐
     │                        │
     └──── suspend ────► suspended
       (only when ready)
```

| Provisioning | Access | Users can use the app? | Response on the tenant subdomain |
|---|---|---|---|
| pending / provisioning / failed | active | No | 404 (unchanged from Milestone 1) |
| ready | active | **Yes** | normal |
| ready | suspended | No | 403 with a generic "suspended" message |

`Tenant::isAccessible()` returns true only for **ready and not suspended**.

Allowed transitions:
- **Suspend** requires `ready` and `active`. Suspending a pending, provisioning or failed tenant, or one that is already suspended, is rejected.
- **Reactivate** requires `suspended`.
- Suspension never changes `status`, and provisioning never runs on a ready tenant, so neither lifecycle can push the other into a contradictory state.

---

## 5. Provisioning Status versus Access Status

- **Provisioning status** (`status`: `pending`, `provisioning`, `ready`, `failed`) records whether the tenant's database and initial data exist. It is changed only by `ProvisionTenant`.
- **Access status** (`App\Enums\TenantAccessStatus`: `active`, `suspended`) is the platform's administrative decision about whether the tenant may be used. It is derived from `tenants.suspended_at`, which is null for `active`. It is changed only by `SuspendTenant` and `ReactivateTenant`.
- The values were not merged: `failed` is never used to mean "suspended", and there is no access value inside `status`. The list shows them in two columns, and both are filterable independently.
- `suspended_at` was chosen over a separate status column because it is the smallest mechanism that also records *when*. Existing tenants automatically read as `active`.

---

## 6. Suspension and Reactivation Workflows

### Suspend
1. Tenant details → **Suspend access** (shown only when the tenant can be suspended).
2. The confirmation page explains that all users lose access immediately and nothing is deleted. A **reason is required** (5–1000 characters).
3. `SuspendTenant` locks the row, verifies `ready` and `active`, sets `suspended_at` and `suspension_reason`, and writes a `tenant.suspended` audit record in the same transaction.
4. The admin returns to the details page, which shows a red callout with the date and reason and a **Reactivate access** button.

Not changed by a suspension: users, roles, permissions, sessions, the tenant database, its files, the tenant and domain records, `status` and `provisioned_at`. The reason is **never shown to tenant users**.

### Reactivate
1. Tenant details → **Reactivate access** (shown only when the tenant is suspended).
2. The confirmation page shows the suspension date and reason, with an optional note.
3. `ReactivateTenant` locks the row, verifies the tenant is suspended, clears `suspended_at` and `suspension_reason`, and writes a `tenant.reactivated` audit record. That record's old values keep the previous suspension date and reason.
4. Nothing is re-provisioned. Users regain access with their existing accounts and data. Sessions that were still within their lifetime work again.

### Invalid requests
Opening a confirmation page or posting an action in the wrong state redirects to the details page with an explanatory error. The tenant and the audit trail are left unchanged.

---

## 7. Tenant Access Enforcement

Enforcement lives in the existing `App\Http\Middleware\EnsureTenantIsReady`, which is part of the `tenant` middleware group:

```
InitializeTenancyBySubdomain → PreventAccessFromCentralDomains → EnsureTenantIsReady → … StartSession … → ScopeSessions → EnsureUserIsActive → auth
```

- It runs **right after tenant identification and before the session starts**. A suspended tenant is therefore refused before any session, cookie or "remember me" token is read, so an existing session cannot bypass it.
- The `tenant` group covers every tenant route: pages, Fortify authentication (login, logout, password reset and confirmation), settings, the employee and role pages, and **Livewire's update endpoint**. All of them return 403 while the tenant is suspended.
- **Unknown tenants** still return 404 (`InitializeTenancyBySubdomain::$onFail`). **Pending, provisioning and failed tenants** still return 404, exactly as before. Suspension produces 403 only for tenants that are otherwise ready.
- **Central routes are not affected:** they are bound to the central domain and do not use the `tenant` group. A Platform Admin can always open and reactivate a suspended tenant's central record.
- **Isolation:** the check reads only the identified tenant's own record, so suspending tenant A cannot affect tenant B (tested).

### Bug found and fixed: stale tenant instance in long-lived processes
stancl's `Tenancy::initialize()` returns early when the same tenant is identified again while already initialized. It keeps the **previously loaded model instance** and discards the freshly resolved one. In a long-lived process (Octane, the test client), a tenant suspended after its first request would therefore keep being served from the stale instance.

The middleware now calls `$tenant->refresh()` before checking, which costs one primary-key query on the central database per tenant request. With PHP-FPM every request was already fresh. A regression test (`an existing session cannot bypass suspension…`) fails without this line: it was verified by temporarily removing it, and both the session test and the Livewire test then returned 200 instead of 403.

---

## 8. Audit Logging

Table `platform_audit_logs` (central), model `App\Models\PlatformAuditLog`:

| Column | Content |
|---|---|
| `platform_admin_id` | The acting Platform Admin (nullable FK, `nullOnDelete`) |
| `actor_email` | A copy of the admin's email, so the record stays meaningful if the admin is removed |
| `tenant_id` | The affected tenant (FK, **`restrictOnDelete`**: a tenant with history cannot be deleted silently) |
| `action` | `tenant.created`, `tenant.updated`, `tenant.suspended` or `tenant.reactivated` (`App\Enums\PlatformAuditAction`) |
| `old_values` / `new_values` | Only the relevant fields, for example `{"name": "Old"} → {"name": "New"}` or `{"access_status": "active"} → {"access_status": "suspended"}` |
| `reason` | The suspension reason or the reactivation note |
| `created_at` | Timestamp (there is no `updated_at`) |

- **Read-only.** There are no routes or UI to change or delete entries. The model throws `LogicException` on update and delete (tested).
- **No secrets.** Records hold only the fields above. The temporary password from tenant creation is never written (tested), and no request payloads are stored.
- **Where to review it.** The latest 5 entries are on tenant details, the full paginated history is at `/admin/tenants/{id}/history`, and the latest 8 entries across all tenants are on the dashboard.
- **Not audited:** CLI actions such as `tenants:provision` and `tenants:sync-authorization`, which have no Platform Admin identity. Platform Admin login and logout are not audited either.
- Updates that change nothing are not audited.

---

## 9. New and Modified Files

### New
| File | Purpose |
|---|---|
| `app/Actions/Platform/SuspendTenant.php` | Suspend with row lock, transition check and audit |
| `app/Actions/Platform/ReactivateTenant.php` | Reactivate with row lock, transition check and audit |
| `app/Actions/Platform/UpdateTenantDetails.php` | Edit the company name; audits only real changes |
| `app/Enums/TenantAccessStatus.php` | `active` / `suspended`, with labels and colors |
| `app/Enums/PlatformAuditAction.php` | Audit action catalog |
| `app/Exceptions/InvalidTenantTransition.php` | Rejected lifecycle transitions |
| `app/Models/PlatformAuditLog.php` | Immutable central audit record |
| `app/Policies/TenantPolicy.php` | Platform Admin authorization for tenant management |
| `app/Http/Controllers/Platform/DashboardController.php` | Statistics (one aggregate query) and recent activity |
| `app/Http/Controllers/Platform/TenantAccessController.php` | Suspend and reactivate confirmation pages and actions |
| `app/Http/Requests/Platform/{UpdateTenantRequest, SuspendTenantRequest, ReactivateTenantRequest}.php` | Validation and authorization |
| `database/migrations/2026_10_19_150000_add_suspension_columns_to_tenants_table.php` | `suspended_at`, `suspension_reason` |
| `database/migrations/2026_10_19_150100_create_platform_audit_logs_table.php` | Audit table |
| `resources/views/platform/dashboard.blade.php` | Dashboard |
| `resources/views/platform/tenants/{show, edit, suspend, reactivate, history}.blade.php` | Tenant pages |
| `resources/views/platform/tenants/partials/audit-entries.blade.php` | Shared audit table |
| `tests/Feature/Platform/{PlatformDashboardTest, TenantManagementTest, TenantSuspensionTest}.php` | 58 tests |

### Modified
| File | Change |
|---|---|
| `app/Models/Tenant.php` | New custom columns and casts; `auditLogs()`; `search` and `whereAccessStatus` scopes; `isSuspended()`, `accessStatus()`, `isAccessible()`, `canBeSuspended()`, `canBeReactivated()`, `provisioningFailureSummary()` |
| `app/Enums/TenantStatus.php` | `color()` for badges |
| `app/Http/Middleware/EnsureTenantIsReady.php` | Re-reads the tenant; 403 for suspended tenants |
| `app/Http/Controllers/Platform/TenantController.php` | List filters and pagination; `show`, `edit`, `update`, `history`; creation audit; failure redirect to details |
| `app/Http/Controllers/Platform/AuthenticatedSessionController.php`, `bootstrap/app.php` | Platform login and "already signed in" redirect go to the dashboard |
| `routes/web.php` | Dashboard and tenant management routes with `can:` middleware |
| `resources/views/layouts/platform.blade.php` | Dashboard nav item; the brand links to the dashboard |
| `resources/views/platform/tenants/index.blade.php` | Search and filter form, access column, details link, pagination |
| `tests/Pest.php` | `centralTenant()` helper (central record only, no tenant database) |
| `tests/Feature/Platform/PlatformAuthenticationTest.php` | Login now lands on the dashboard |
| `tests/Feature/Tenancy/TenantProvisioningTest.php` | A failed creation now redirects to the tenant details page |

No packages were installed or changed. No tenant migrations were added.

---

## 10. Migrations and Database Changes

Both migrations are **central and additive**: nothing is dropped or renamed, and existing rows keep their values.

| Migration | Change |
|---|---|
| `2026_10_19_150000_add_suspension_columns_to_tenants_table` | `tenants.suspended_at` (timestamp, nullable, indexed) and `tenants.suspension_reason` (text, nullable). Existing tenants read as active. |
| `2026_10_19_150100_create_platform_audit_logs_table` | A new `platform_audit_logs` table (see §8) with FKs to `platform_admins` (set null) and `tenants` (restrict). |

The new columns are registered in `Tenant::getCustomColumns()`, so stancl stores them as real columns instead of in the `data` JSON. Tenant databases are untouched.

**Applied to the development database:** `php artisan migrate` was run on `fcl_central`, which had 0 tenants, after reviewing the SQL with `--pretend`. The testing database is migrated by the test suite.

---

## 11. Security Considerations

- **Authentication and authorization:**
  - Every management route needs `auth:platform` plus a `can:` check against `TenantPolicy`, and form requests authorize again.
  - The platform area stays bound to the central domain and returns 404 from a tenant subdomain (tested).
  - Guests are redirected to the platform login for every management route, and nothing changes (tested for each route).
- **No trusted browser identifiers:**
  - The tenant comes from route model binding on the central `tenants` table, so an unknown ID returns 404.
  - Only `name` is accepted by the update action (an `Editable` allow-list). Attempts to send `id`, `status`, `subdomain` or `suspended_at` are ignored (tested).
  - Filter values are parsed with `tryFrom()`, and search wildcards are escaped.
- **Lifecycle integrity:** the backend enforces transitions inside a locked transaction, independent of which buttons the UI shows.
- **No credential exposure:**
  - The details page shows the database **name** only.
  - The raw `data` column is never rendered.
  - `provisioningFailureSummary()` cuts off the `(Connection: …, Host: …, SQL: …)` part that Laravel database exceptions append, redacts `password=…`, `token: …`, `secret=…` and `key=…` patterns, and limits the summary to 300 characters (tested).
  - Exception traces are never displayed.
- **Suspension message:** tenant users see a generic message. The internal reason stays in the central database.
- **Isolation unchanged:** the middleware order, `ScopeSessions`, `EnsureUserIsActive`, the tenant-only Livewire endpoint and the permission cache bootstrapper are all as they were in Milestones 1 and 2.

---

## 12. Automated Tests and Results

58 new tests (166 in the suite):

| File | Tests | Covers |
|---|---|---|
| `PlatformDashboardTest` | 5 | Guest redirect, counts by both statuses from real records, zero state, recent activity, landing after login |
| `TenantManagementTest` | 29 | Guest redirect on every management route (7), not reachable from a tenant subdomain, list, search and filters (7 cases, including literal `%` and ignored unknown values), pagination, details without credentials, sanitized failure summary and redaction, unknown tenant 404, rename with audit, allow-list of editable fields, name validation (3), creation audit without the password, history scoped to one tenant, read-only audit records |
| `TenantSuspensionTest` | 24 | Suspend with reason and audit, reason validation (4), non-ready tenants cannot be suspended (3), double suspension, reactivating an active tenant, action-level rejection, stale-instance protection in actions, suspended central record visible, every tenant route returns 403 (5), login refused, **existing session blocked and restored after reactivation**, **Livewire update refused** (with a real component snapshot), **tenant A suspension does not affect tenant B**, provisioning state and tenant data (users and roles) intact through suspend and reactivate, non-ready tenants still return 404 |

How the 15 required test areas are covered:
1. Viewing tenants: the list and details tests.
2. Unauthorized access: the guest dataset and the tenant-subdomain test.
3. Search and filters: the dataset.
4. Details from the central database: the details test.
5. Updating company information: the rename test and the allow-list test.
6. Suspension requires authorization and a reason: the guest dataset and the reason dataset.
7. Suspended tenants are blocked: the route dataset and the login test.
8. Existing sessions: the session test.
9. Livewire: the Livewire test.
10. Reactivation restores access: the session test and the data-integrity test.
11. Tenant A does not affect tenant B: the isolation test.
12. Provisioning status stays separate: the data-integrity test and the non-ready tests.
13. Audit actor and action: the suspend, rename, creation and reactivation tests.
14. Invalid transitions: the non-ready, double-suspend, reactivate-active and action-level tests.
15. Milestones 1 and 2: the full suite.

**Results (final run):**
- `composer test`: Pint passed, PHPStan **0 errors**, Pest **166 tests, 165 passed, 1 skipped, 628 assertions**. The skipped test is the Starter Kit's two-factor test, skipped because the Fortify 2FA feature is disabled, as in Milestones 1 and 2.
- `php artisan test`: same result, 166 tests (165 passed, 1 skipped).
- `npm run build`: succeeds. `npm audit`: **0 vulnerabilities**.
- After the run, only `fcl_central` and `fcl_central_testing` exist. Every disposable `fcl_test_tenant_*` database was dropped.

**Real-server check** (PHP built-in server, testing databases only, all data removed afterwards). Two tenants were provisioned through the web UI, then:

| Check | Result |
|---|---|
| Platform login lands on the dashboard; dashboard, search, details and history pages | 302 to `/admin`, then 200 |
| Employee logins on both tenants | 302 to the dashboard, then 200 |
| Real Livewire update on tenant A (active) | 200 |
| Suspend without a reason | 302 back to the form (not suspended) |
| Suspend with a reason | 302 to the details page |
| Tenant A, **same session**: dashboard and Livewire update | **403 / 403** |
| Tenant A login page and login POST | 403 / 403 |
| Suspension reason shown to tenant users | no (0 occurrences) |
| Tenant B dashboard | 200 (unaffected) |
| Central state, tenant data and tenant session rows | `status=ready`, suspended; users kept; tenant session rows 1 before and 1 after |
| Reactivate; tenant A, same session: dashboard and Livewire update | 302; **200 / 200** |
| Audit trail | created, created, suspended, reactivated, all attributed to the acting admin |
| Server log errors | 0 |

**Browser testing:** not performed. No browser-automation tool was available, so page layout and visual details are unverified. Follow §13.

---

## 13. Manual Browser Verification Steps

Prerequisites: `php artisan migrate` (already applied to the dev database), `npm run build` or `npm run dev`, a Platform Admin (`php artisan platform:create-admin`), and `php artisan serve`.

1. Open `http://localhost:8000/admin/login` and sign in. You should land on the **Dashboard**. All counts should be 0 if there are no tenants.
2. **Tenants → New tenant:** create `Acme Freight` / `acme` and `Globex Freight` / `globex`. Note each temporary password.
3. Back on the Dashboard, *Total* = 2 and *Ready & active* = 2. *Recent administration activity* lists two "Tenant created" entries. Click a count card and confirm it opens the matching filtered list.
4. On **Tenants**, search `glob` (only Globex), set *Access = Suspended* (empty), then **Reset**.
5. Open **Acme Freight**. Check the tenant ID, database `fcl_tenant_<id>`, both status badges and the provisioned date. Confirm no password or connection details are shown.
6. **Edit:** rename it to `Acme Global Freight`, save, and check the success message and the "Details updated" history entry (old → new).
7. In a separate browser profile or private window, sign in at `http://acme.localhost:8000/login` as the Acme Tenant Admin, complete the password change, and open **Employees**.
8. Back in the platform window, click **Suspend access**. Submit with an empty reason to see the validation error. Then enter a reason and confirm. The details page should show the red suspension callout and a **Reactivate access** button.
9. In the tenant window (still on **Employees**), type in the search box, which sends a Livewire request. Livewire shows the refused response in an error overlay. Then click any link. Both should give **403 "Access to this company account has been suspended…"**. The reason must not appear. Reload `/login` and it should also be 403.
10. Open `http://globex.localhost:8000/login`. It should still work normally.
11. In the platform window, check the dashboard: *Suspended* = 1 and *Ready & active* = 1. Filter the list by *Access = Suspended*.
12. Click **Reactivate access**, add an optional note and confirm. Reload the tenant window: the same session works again, and the employees and roles are unchanged.
13. Open **Full history** on the tenant: created, details updated, access suspended (with reason), access reactivated (with note), newest first, with no edit or delete controls.

---

## 14. Known Limitations and Deferred Functionality

- **Single Platform Admin role.** Every Platform Admin has full tenant-management rights. `TenantPolicy` is the place to add platform roles.
- **Editable scope:** only the company name. Subdomain renaming, database renaming, custom domains and tenant deletion are out of scope.
- **Failed provisioning recovery stays CLI-only** (`php artisan tenants:provision {id|subdomain}`). A web "retry" button would need asynchronous provisioning (a queue) to avoid long HTTP requests, so it is deferred. Failed databases are never deleted or recreated automatically.
- **Sessions survive a suspension.** They are blocked, not deleted, as required. If the tenant is reactivated within the session lifetime, users are still signed in. If a hard logout on suspension is ever needed, it would mean deleting tenant session rows, which this milestone deliberately does not do.
- **CLI maintenance ignores suspension.** `tenants:migrate` and `tenants:sync-authorization` still run on suspended tenants, which is intended so schema upgrades are not skipped. There are no tenant queue jobs yet. When jobs exist, decide whether they should run for suspended tenants.
- **Not audited:** CLI provisioning and maintenance commands, Platform Admin login and logout, and changes made directly in the database.
- **Livewire upload routes** (`livewire/upload-file`, `livewire/preview-file`) are registered by Livewire with the `web` middleware only, not the `tenant` group (unchanged since Milestone 1). They need a signed URL, run in central context and give no access to tenant data. No component uses file uploads yet. Bind them to the tenant context when uploads are introduced.
- **No browser-automation test.** Rendering was verified through HTTP tests and the real server, not in a browser.
- **The suspension page** is Laravel's default 403 error page with a custom message. A branded page can come later.

---

## 15. Local Setup Instructions

For an existing Milestone 2 checkout:

```bash
git pull                     # (when a remote exists)
composer install
php artisan migrate          # applies the two additive central migrations
npm install && npm run build
php artisan serve            # http://localhost:8000/admin
```

No tenant migrations were added, so `php artisan tenants:migrate` is not required for this milestone. It is still safe to run. No new environment variables or packages were added.

Run the checks:

```bash
composer test                # Pint, PHPStan, Pest (uses fcl_central_testing + fcl_test_tenant_*)
npm run build
npm audit
```

---

## 16. Recommended Next Milestone

**Milestone 4 — Subscription Plans & Billing Foundation**, building on this milestone's access lifecycle:
- plans, seats and annual subscription periods in the central database;
- automatic suspension when a subscription lapses, reusing `SuspendTenant` with a system actor, which would need a nullable actor in the audit trail;
- renewal and reactivation through `ReactivateTenant`.

Alternatively, if FCL operations should come first, begin the tenant-side domain (shipments and customers) on the existing tenant, permission and branch foundation.
