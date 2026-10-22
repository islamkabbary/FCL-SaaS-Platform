# FCL Project Context

**Status:** Phase 0 knowledge base · **Verified against code:** 2026-10-22 at commit `bcd7c38` (Milestone 5.1 docs) · **Owner:** Islam Kabbary

This document explains what the product is, what already exists in the repository, and where the FCL Operations module must fit. Statements tagged **[CODE]** were verified by reading the current source. Milestone reports are evidence of earlier work; where they and the code differ, the code wins.

---

## 1. Product vision

A multi-tenant SaaS for **freight forwarding companies**. Each company is one tenant with its own database and subdomain. The first operational product built on top of the SaaS layer is **Egyptian FCL Ocean Import**: following an import shipment from the Pre-Alert to release, delivery, empty return and operational and financial closure, replacing today's Excel sheets and scattered documents (handover §1.1–1.2).

### 1.1 Scope (owner-confirmed, see [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md) BD-S01–S06)

| In the initial scope | Out of the initial MVP |
|---|---|
| Egyptian FCL Ocean Import | Air freight |
| Shipments via an Origin Agent | Export |
| Shipments via a Direct Shipping Line | LCL operations |
| A company with an in-house Customs Clearance team | Advanced accounting / general ledger |
| Branches inside the company | Unrelated logistics modules (warehouse, mobile app, customer portals, AI extraction) |

Future extensibility matters, but must not add complexity to the MVP.

### 1.2 Intended users (inside a tenant)

| User | Role in FCL | Confirmed? |
|---|---|---|
| Documentation employee | Receives the Pre-Alert and opens the Import Job | **Confirmed** (BD-P01, BD-P02) |
| Operations employee | Transit, arrival, release coordination, trucking, empty return | Proposed (research swimlanes) |
| Customs Clearance employee | Customs declaration, examination, customs release | Proposed; in-house team confirmed (BD-S04) |
| Finance employee | Charges, costs, payments, financial closure | Proposed |
| Supervisor / manager | Cross-job visibility, reassignment, closure | Proposed |
| Tenant Admin | Users, roles, permissions, subscription (exists today) | [CODE] |

External stakeholders (no system access in the MVP): importer/customer, foreign exporter/shipper, origin agent, shipping line, terminal, Egyptian Customs / NAFEZA, trucking companies, empty depots.

---

## 2. The SaaS baseline (Milestones 1–5.1)

| Milestone | Delivered | Doc |
|---|---|---|
| 1 | Central DB + one DB per tenant, subdomain identification, provisioning, tenant-scoped sessions | [MILESTONE_01](../MILESTONE_01_SAAS_FOUNDATION.md) |
| 2 | Tenant users, roles, permissions (spatie), privilege-escalation rules | [MILESTONE_02](../MILESTONE_02_TENANT_USERS_PERMISSIONS.md) |
| 3 | Central platform: tenant management, suspension, platform audit | [MILESTONE_03](../MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md) |
| 4 | Annual plans, seats, proration, manual billing, subscription-based access | [MILESTONE_04](../MILESTONE_04_SUBSCRIPTIONS_BILLING.md) |
| 5 | Restricted support access, employee view, Platform Admin 2FA, upload lockdown | [MILESTONE_05](../MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md) |
| 5.1 | Deep-review fixes: strict host identification, sessions, billing integrity, concurrency | [MILESTONE_05_1](../MILESTONE_05_1_SECURITY_BILLING_HARDENING.md) |

Readiness: [SAAS_MVP_READINESS.md](../SAAS_MVP_READINESS.md). Local development: **pass with limitations**. Staging: **conditional**. Production: **blocked** (backups, monitoring, deploy/rollback, secrets, mandatory admin 2FA decision, commercial policies).

### 2.1 Verification status of M5 and M5.1 (from repository evidence)

| Check | M5 | M5.1 |
|---|---|---|
| Automated tests | Passed (reported in the M5 doc §17) | **427 tests: 426 passed, 1 skipped**, 1,882 assertions; PHPStan level 7 with 0 errors; Pint clean (M5.1 §7, 2026-10-20) |
| Real-server run (curl, cookie jars) | Done: 42 checks (M5 §17.2) | **Not done** (M5.1 §7: "no real-server run and no browser run") |
| Owner browser verification | **Not done** (readiness §3; handover §0: "لم يؤكد بعد") | **Not done** (steps in M5.1 §9) |

Automated tests passing is **not** browser verification, and neither is production readiness. Phase 0 did not run the test suite.

---

## 3. Technical stack [CODE]

| Component | Version (composer.lock) |
|---|---|
| PHP | 8.4 runtime (constraint `^8.3`) |
| Laravel | 13.37 |
| Livewire | 4.4 (single-file `⚡` page components) |
| Flux UI (free) | 2.20 |
| Fortify | 1.41 (tenant features: password reset only) |
| stancl/tenancy | 3.10.1 (multi-database) |
| spatie/laravel-permission | 8.3 (no teams) |
| Pest | 5.3 |
| Larastan | 3.9, level 7 |
| MySQL | 8.4 (local) |

---

## 4. Architecture as it exists today [CODE]

### 4.1 Code organisation

The owner describes the target as a **modular monolith**. Today the code is a **conventional Laravel monolith grouped by technical type**, with feature subfolders:

- `app/Actions/<Feature>/` holds single-purpose actions with `handle()`. Authorization and business rules live here, e.g. `CreateEmployee` calls `Gate::forUser($actor)->authorize(...)`.
- `app/Billing/` holds domain services and value objects (`Money`, `Pricing`, `Proration`, `SeatGuard`).
- `app/Impersonation/`, `app/Tenancy/`, `app/Enums/`, `app/Policies/`, `app/Models/` (+ `Concerns/IsAppendOnly`), `app/Http/Middleware/`.
- There is no `app/Modules`, no `app/Domain`, no `app/Services`, no `app/Jobs`.
- Tenant UI: `resources/views/pages/<area>/⚡index.blade.php`, routed with `Route::livewire(...)`. Central admin UI: plain Blade controllers under `app/Http/Controllers/Platform`.

FCL code can follow the same shape: `app/Actions/ImportJobs/...`, `app/Enums/...`, `app/Policies/...`, `resources/views/pages/import-jobs/...`. A dedicated base folder such as `app/Fcl/` would need the owner's approval (AGENTS.md: no new base folders without approval). See Q-X01 in [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md).

### 4.2 Tenancy

- **Identification.** `InitializeTenancyByStrictSubdomain` accepts only one DNS label directly under `CENTRAL_DOMAIN`; everything else returns 404. `trustHosts` is applied outside `local`.
- **Tenant middleware group** (`bootstrap/app.php`), in order:
  1. `InitializeTenancyByStrictSubdomain`
  2. `PreventAccessFromCentralDomains`
  3. `EnsureTenantIsReady`: 404 when not provisioned, 403 when suspended
  4. `ScopeSessions`
  5. `EnforceImpersonation`
  6. `EnsureUserIsActive`
  7. `ThrottlePasswordResetLinkRequests`
- **Databases.** Central `fcl_central`; each tenant has `fcl_tenant_<uuid>` (`TENANCY_DB_PREFIX`). Tenant migrations live in `database/migrations/tenant/`.
- **Bootstrappers.** Database, Filesystem (`local` and `public` are suffixed per tenant), Queue, and a custom `PermissionCacheBootstrapper`.
  - **`CacheTenancyBootstrapper` is off.** The cache is central.
  - Rule for FCL: **do not cache tenant data** until Redis and the cache bootstrapper are enabled (readiness §5.2).
- **Sessions.** Database driver. Tenant sessions are stored in the tenant DB through `TenantAwareDatabaseSessionHandler`, and `SESSION_DOMAIN` is empty, so cookies are host-only.
- **Queues.** The `database` queue is on the central connection. `QueueTenancyBootstrapper` carries tenant context.
  - **No queued job exists yet**, and tests use the `sync` driver.
  - FCL's first queued job will be the first real test of the queue tenancy behaviour. See [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) §6.
- **Provisioning.** `ProvisionTenant` creates the DB, migrates it, seeds the `MAIN` branch, runs `SyncTenantAuthorization` and creates the first Tenant Admin. It runs synchronously.

### 4.3 Tenant tables today

These are all of them:
- `branches`: id, name, code (unique), is_default
- `users`: with `primary_branch_id`, `is_active`, `must_change_password`
- `password_reset_tokens`, `sessions`
- spatie `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`
- `impersonation_sessions`, `impersonation_events`
- `notifications`

**At Phase 0 there was no tenant business audit table and no FCL table.** M1-A added `tenant_audit_logs` (2026-10-22); there is still no FCL business table.

Central tables include:
- `tenants`, `domains`
- `platform_admins`, `platform_audit_logs`
- `subscription_plans`, `tenant_subscriptions`, `subscription_terms`
- `billing_charges`, `billing_charge_lines`, `billing_payments`
- `seat_change_requests`
- `support_sessions`, `support_session_events`
- `jobs`, `cache`

### 4.4 Central vs tenant models

- **Central** (`CentralConnection` trait):
  - `Tenant`, `PlatformAdmin`, `PlatformAuditLog`
  - `SubscriptionPlan`, `TenantSubscription`, `SubscriptionTerm`, `SeatChangeRequest`
  - `BillingCharge`, `BillingChargeLine`, `BillingPayment`
  - `SupportSession`, `SupportSessionEvent`
- **Tenant** (default connection): `User`, `Branch`, `ImpersonationSession`, `ImpersonationEvent`, and the spatie `Role` and `Permission`.

**FCL models must use the default (tenant) connection and must never use `CentralConnection`** (R1 L179).

### 4.5 Users and branches

- Every user has one required `primary_branch_id`.
- `CreateEmployee` always assigns the `MAIN` branch, and `BranchPolicy::create()` always returns false. There is no branch UI, and `POST /branches` returns 404 by test.
- **There is no branch-scoped visibility anywhere yet.** The future rules exist only as documentation (handover §2.1–2.2): own, selected or all branches; one primary branch per shipment; a transfer needs a permission, a reason and an audit.

### 4.6 Authorization

- **Permission catalog.** The enum `App\Enums\TenantPermission` currently has: `employees.view|create|update|deactivate`, `roles.view|manage|assign`, `billing.view|manage`. Its docblock reserves room for FCL permissions.
- **Roles.** `TenantRole`: `Tenant Admin` and `Employee`.
- **Sync.** `SyncTenantAuthorization`, run by `php artisan tenants:sync-authorization`, does three things:
  - creates every enum permission;
  - gives **Tenant Admin all permissions** automatically;
  - creates `Employee` with **no** permissions and never resets it.

  This means a new FCL permission is held only by Tenant Admins until someone grants it.
- **Policies.** `before()` denies a non-tenant identity. Checks go through `hasPermissionTo`, and `PrivilegeGuard` prevents granting permissions the actor does not hold.
- **Gate ordering.** The spatie Gate registration is disabled in config and re-registered by `ImpersonationServiceProvider` *after* the impersonation `Gate::before`, so restrictions can deny permission-named abilities.
- **Livewire authorization is defence in depth:**
  - a route `can:` middleware;
  - `$this->authorize()` in `mount()` and in every action method;
  - a further authorization inside the action class.

  Persistent Livewire middleware: `EnsureTenantHasActiveSubscription`, `EnsurePasswordIsChanged`.
- **No global "every route declares a permission" check exists.** Deny-by-default is a convention plus Laravel's default of denying undefined abilities.

### 4.7 Impersonation (support access, employee view)

- **Allowlist.** `App\Impersonation\RestrictionPolicy` is enforced at three layers: HTTP (non-GET refused), Livewire (method calls and property updates), and Gate (`Gate::before`).
- **Session limits.** Support access is off by default, needs 2FA, and lasts 15 minutes. Employee view also lasts 15 minutes.
- **Audit guard.** `PlatformAuditLog::recordForTenantUser` refuses to attribute an action to the impersonated user.

### 4.8 Audit

- **Central only:** `PlatformAuditLog` (`platform_audit_logs`).
  - It is append-only at model level.
  - It records the actor type (`platform_admin`, `tenant_user`, `system`), the action enum, old/new values and a reason.
  - It is written explicitly inside actions; there is no observer or trait.
- **No tenant-side business audit exists.** Employee and role changes are not audited. The only tenant-DB trail is `impersonation_events`.
- FCL therefore needs a **new tenant-DB audit/timeline mechanism**; shipment data must never be written to the central audit (BD-T10). This is an M1-A deliverable.

### 4.9 Subscription enforcement

- `EnsureTenantHasActiveSubscription` allows only the `Legacy` and `Active` statuses. Otherwise it **blocks** operational routes completely: Livewire/JSON requests get 403, page requests are redirected to `subscription-required`.
- There is **no read-only mode and no grace period**.
- FCL routes must sit inside the existing `subscription.active` group. Whether expired tenants should be able to read their FCL data is a business question (Q-X02).
- Manual suspension (`EnsureTenantIsReady`) blocks every tenant request.

### 4.10 Uploads and storage

- Livewire's temporary upload and preview endpoints are **disabled**: `RejectTemporaryUploads` returns 404 on every host (`TenancyServiceProvider`). The reason is that they run outside tenant context.
- The `local` disk's `serve` routes are also off.
- **No feature stores files.** FCL document management (M2) needs a tenant-aware upload design first (BD-SEC03). Phase 0 changes nothing here.

### 4.11 UI and localisation

- Tenant pages are Livewire 4 single-file components using Flux UI, `WithPagination`, `#[Url]` filters and `#[Computed]` queries.
- The sidebar is `resources/views/layouts/app/sidebar.blade.php`, with `@can` groups.
- **No `lang/` directory, no Arabic translations, no RTL.** Strings use `__()`. Arabic UI is an open question (Q-X03).

### 4.12 Tests

**Layout.** Pest suites: `tests/Feature/{Auth,Billing,Impersonation,Platform,Settings,Tenancy}` plus `tests/Unit`.

**Helpers in `tests/Pest.php`:**
- tenant setup: `provisionTenant()`, `actingInTenant()`;
- users: `tenantAdminUser()`, `userWithPermissions([...])`;
- impersonation: `startSupportSession()`, `startEmployeeView()`;
- real Livewire update requests: `livewireRequest()`;
- concurrency proofs: `firstQueriesOfTransactions()`.

**Database safety (`TestCase`).** It refuses to run unless:
- the environment is `testing`;
- the central DB is `fcl_central_testing`;
- the tenant prefix is `fcl_test_tenant_`.

It also drops the tenant databases each test created.

**Other notes.**
- The opt-in review-proof group no longer exists; it was converted into regular tests in 5.1.
- Practice since 5.1: every fix test is run once without its fix and must fail.

---

## 5. Constraints FCL inherits

| # | Constraint | Source |
|---|---|---|
| 5.1 | FCL transactions live only in the tenant DB; SaaS plans, seats and billing stay central | BD-T10, BD-T11; [CODE] §4.4 |
| 5.2 | Shipment charges, costs and profitability are unrelated to SaaS billing; never reuse `app/Billing` proration for shipments | BD-T12; R1 L1073 |
| 5.3 | Every FCL write action authorises inside the action, not only in the component | [CODE] §4.6 pattern |
| 5.4 | **Impersonation:** the allowlist allows the generic abilities `viewAny`/`view` and every GET request for reviewed SaaS pages. **Since M1-A (2026-10-22) FCL is denied in every layer, reads included** (BD-SEC04): FCL routes, components and resources are recognised by naming convention *and* by marker/middleware. Follow the conventions in [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) §4.1 | `RestrictionPolicy`, `DenyDuringImpersonation` |
| 5.5 | New FCL permissions are given to Tenant Admin automatically by the sync; employees get them only through roles | `SyncTenantAuthorization` |
| 5.6 | Do not cache tenant data (central cache) | `config/tenancy.php` bootstrappers |
| 5.7 | The first queued FCL job must prove tenant context initialises and reverts, and fails closed | R1 L186–196; no jobs exist yet |
| 5.8 | Uploads stay disabled until a tenant-aware design exists | M5 §12.1 |
| 5.9 | Tenant audit: built in M1-A as `TenantAuditLog` (tenant DB, append-only, written inside the change's transaction). FCL writes must record through it | BD-AUD01; plan §4.2 |
| 5.10 | Only the `MAIN` branch exists, so branch-scoping tests must seed extra branches. A branch-management UI is a separate decision | [CODE] §4.5; Q-BR02 |
| 5.11 | Money uses exact decimals (`brick/math`, `App\Billing\Money` pattern), never floats | [CODE]; R1 L806 |
| 5.12 | Tenant migrations run with `php artisan tenants:migrate`, and permissions sync with `tenants:sync-authorization`, as deployment steps | readiness §4 |

---

## 6. Dependencies and gates outside FCL

- **Owner browser verification of M5 and M5.1** is still outstanding. It does not block FCL M1-A, but it should be done before FCL work starts relying on impersonation behaviour in the browser (recommended by R1 L1175 and R2 L981).
- **Production blockers** (readiness §5.1) are unchanged and independent of FCL.
- **Open low-severity notes from M5.1 §8** that matter for FCL:
  - Livewire `__dispatch` and `updating*` hooks run before the impersonation listener. The first FCL component that uses `#[On]` handlers or `updating*` hooks must be reviewed.
  - Audit is append-only at model level only.
