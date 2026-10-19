# Milestone 1 — SaaS Foundation

This document describes what was **actually implemented and verified** in Milestone 1 of the FCL SaaS Platform. It is written for developers joining the project.

---

## 1. Overview and Objectives

The FCL SaaS Platform will be a commercial, multi-tenant application for freight forwarding companies. Milestone 1 builds only the **multi-tenant foundation**. No FCL business functionality exists yet.

**Goal (met):** two or more independent companies (tenants) can be provisioned. Each one gets its own MySQL database, its own subdomain, an automatically created Default Branch and an initial Tenant Admin, with data isolation verified by automated tests and a real-server smoke test.

| Objective | Status |
|---|---|
| Central MySQL database for platform administration | Done |
| Platform Admin authentication, separate from tenant users | Done |
| `stancl/tenancy` v3, multi-database tenancy | Done (v3.10.1) |
| Tenant identification by subdomain | Done |
| Manual tenant provisioning by the Platform Admin | Done (web UI + resume command) |
| Separate database per tenant + tenant migrations | Done |
| Automatic Default Branch | Done |
| Initial Tenant Admin with a one-time temporary password | Done |
| Forced password change on first login | Done |
| Tenant authentication on the tenant's subdomain | Done |
| Tenant Admin cannot create branches | Done (policy + no route) |
| Isolation and security tests | Done |

---

## 2. Implemented Features

### Central (platform) application — `http://localhost:8000`
- **Platform Admin login** at `/admin/login`. It uses its own `platform` guard and its own `platform_admins` table, and it is rate limited (5 failed attempts per email and IP).
- **Tenant list** at `/admin/tenants`, showing company, subdomain and provisioning status.
- **Create tenant** at `/admin/tenants/create`: company name, subdomain, Tenant Admin name and email.
- **Provisioning result page**, rendered directly in the POST response. It shows the login URL, the admin email and the **temporary password, exactly once**, and is sent with `Cache-Control: no-store, private`.
- **Console commands:**
  - `php artisan platform:create-admin`: creates a Platform Admin. The password is always typed interactively and is never hardcoded or seeded.
  - `php artisan tenants:provision {id|subdomain}`: resumes provisioning for a tenant that is not ready, for example after a failure.

### Tenant application — `http://{subdomain}.localhost:8000`
- The Starter Kit's Fortify login, logout and password reset, plus the dashboard and the settings pages (profile, security, appearance). All of them run against the **tenant's own database**.
- **Forced password change:** a user whose `must_change_password` flag is set is redirected to `/settings/security` until they change their password.
- Public registration stays **disabled**, because Fortify's `registration` feature is not enabled.

---

## 3. Architecture

- **Laravel 13 modular monolith** with **database-per-tenant** isolation.
- **One central database** (`fcl_central`) holds platform data: tenants, domains, platform admins, central sessions, cache and jobs.
- **One database per tenant** (`fcl_tenant_<tenant-uuid>`) holds company data: branches, users, password reset tokens and tenant sessions.
- **Subdomain per tenant.** The subdomain is looked up in the central `domains` table, and the matching tenant's database becomes the default connection for the rest of the request.

```
                       ┌──────────────────────────────┐
 localhost:8000  ────► │ Central app (routes/web.php)  │ ──► fcl_central
                       │ guard: platform               │     tenants, domains,
                       └──────────────────────────────┘     platform_admins, sessions,
                                                             cache, jobs
                       ┌──────────────────────────────┐
 acme.localhost  ────► │ Tenant app (routes/tenant.php │ ──► fcl_tenant_<acme uuid>
 globex.localhost ───► │  + Fortify + Livewire)        │ ──► fcl_tenant_<globex uuid>
                       │ guard: web                    │     branches, users,
                       └──────────────────────────────┘     password_reset_tokens, sessions
```

The code follows the existing Laravel directory conventions. No new top-level module folders were created in this milestone.

---

## 4. Central vs Tenant Database Separation

| Table | Central (`fcl_central`) | Tenant (`fcl_tenant_*`) |
|---|---|---|
| `tenants`, `domains` | ✅ | — |
| `platform_admins` | ✅ | — |
| `sessions` | ✅ (platform sessions) | ✅ (tenant sessions) |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | ✅ | — |
| `branches` | — | ✅ |
| `users`, `password_reset_tokens` | — | ✅ |

There is deliberately **no `users` table in the central database**, so any accidental query for tenant users in central context fails loudly instead of silently returning data. A test asserts this.

**Connections** (`config/database.php`):
- `mysql`: the central connection, and the default outside tenant context.
- `tenant_template`: the template stancl uses to build each tenant's `tenant` connection. The tenancy database manager also uses it to run `CREATE DATABASE`. It is kept separate from `mysql` because DDL statements commit implicitly in MySQL, and running them on the central connection would commit any open central transaction.
- `tenant`: created at runtime by stancl when tenancy is initialized. The name is reserved by the package.

The `cache` and `queue` database drivers are pinned to the central `mysql` connection (`config/cache.php`, `config/queue.php`), so they behave the same whatever the current context.

**Central models** use stancl's `CentralConnection` trait: `Tenant`, `Domain` and `PlatformAdmin`. **Tenant models** use the default connection: `User` and `Branch`.

---

## 5. Multi-Tenancy Configuration (`config/tenancy.php`)

| Setting | Value | Why |
|---|---|---|
| `tenant_model` | `App\Models\Tenant` | Custom columns: `name`, `status`, `provisioning_error`, `provisioned_at` |
| `id_generator` | UUID | Tenant IDs are UUIDs |
| `central_domains` | `[env('CENTRAL_DOMAIN', 'localhost')]` | Exactly one central domain (central routes are bound to it) |
| `bootstrappers` | Database, Filesystem, Queue | `CacheTenancyBootstrapper` is **disabled**: it needs a tag-capable cache store (Redis), and this project uses the `database` cache store |
| `database.central_connection` | `mysql` | |
| `database.template_tenant_connection` | `tenant_template` | See §4 |
| `database.prefix` | `env('TENANCY_DB_PREFIX', 'fcl_tenant_')` | The test suite uses `fcl_test_tenant_` |
| `filesystem.asset_helper_tenancy` | `false` | Otherwise `asset()` and Vite assets would be rewritten to per-tenant URLs and the CSS/JS would break on tenant pages |
| `routes` | `false` | stancl's tenant-asset route is not used |

`App\Providers\TenancyServiceProvider` is registered in `bootstrap/providers.php`. Compared with the generated stub:
- **No automatic job pipelines** on `TenantCreated` or `TenantDeleted`. Provisioning is orchestrated explicitly by `ProvisionTenant`, so it can track status. Tenant deletion is out of scope, so **no database is ever dropped automatically**.
- Tenancy middleware is prepended to the HTTP kernel's priority list. The kernel is resolved through the `Illuminate\Contracts\Http\Kernel` binding; resolving the concrete class would build a second kernel (see §16).
- A failure to identify a tenant (unknown subdomain) returns **404** instead of an exception.
- **Livewire's update endpoint** is registered with the `web` + `tenant` middleware, because only the tenant application uses Livewire components.

---

## 6. Subdomain Routing

**Route files**
- `routes/web.php`: **central routes**, wrapped in `Route::domain(config('tenancy.central_domains')[0])`. They exist only on the central host; on a tenant host the URL does not match them.
- `routes/tenant.php`: **tenant routes**, loaded in `bootstrap/app.php` through the `then:` callback **after** the central routes, inside the `web` + `tenant` middleware groups. Load order matters because tenant routes have no domain constraint: the domain-bound central `/` must be registered first to win on `localhost`.
- `routes/settings.php`: the Starter Kit settings pages, included from `routes/tenant.php`.
- **Fortify routes** (login, logout, password reset) get tenant context from `config/fortify.php` → `'middleware' => ['web', 'tenant']`, without any vendor route edits.

**The `tenant` middleware group** (`bootstrap/app.php`):

| Order | Middleware | Responsibility |
|---|---|---|
| 1 | `InitializeTenancyBySubdomain` | Resolves `{sub}` from `{sub}.{central domain}` and switches the default DB connection to the tenant database |
| 2 | `PreventAccessFromCentralDomains` | Returns 404 for tenant routes on the central host |
| 3 | `EnsureTenantIsReady` (app) | Returns 404 unless the tenant's `status` is `ready` |
| 4 | `ScopeSessions` (stancl) | Binds the session to the tenant; a session carrying a different tenant ID gets **403** |

**Effective execution order.** It is enforced through the middleware priority list and covered by a test:

```
PreventAccessFromCentralDomains > InitializeTenancyBySubdomain > EnsureTenantIsReady
  > EncryptCookies > … > StartSession > … > ScopeSessions > Authenticate > …
```

- Tenancy initializes **before the session starts**, so tenant sessions are read from and written to the tenant database.
- `ScopeSessions` runs **before `Authenticate`**, so the guard never resolves a user ID from another tenant's session.

**Subdomain validation** (`StoreTenantRequest`): 3–40 characters matching `^[a-z0-9]+(-[a-z0-9]+)*$`. Input is lowercased. The value must be unique in `domains`, and these are reserved: `admin, api, app, assets, central, dashboard, ftp, mail, platform, smtp, static, status, support, www`.

---

## 7. Tenant Provisioning Workflow

Implemented in `App\Actions\Tenancy\ProvisionTenant`, which is called by `Platform\TenantController@store` and by `tenants:provision`.

Provisioning spans the central database, a new database and that database's schema, so **it is not atomic**. It is designed to be **idempotent and resumable**, and it never drops anything.

| Step | What happens | Where |
|---|---|---|
| 1 | Insert the `tenants` row (status **`pending`**, name, initial admin name and email) and its `domains` row (the subdomain), inside **one central transaction** | central |
| 2 | Set status to **`provisioning`** and clear `provisioning_error` | central |
| 3 | **Create the database** `fcl_tenant_<uuid>` (stancl `CreateDatabase` job, run synchronously). If the database already exists from an earlier failed attempt, this step is skipped | MySQL |
| 4 | **Run tenant migrations** (`tenants:migrate --tenants=<id>` against `database/migrations/tenant`); a non-zero exit code is a failure | tenant |
| 5 | Inside **one tenant transaction**: `firstOrCreate` the **Default Branch** (`code = MAIN`, `name = Main Branch`, `is_default = true`), then create or update the **Tenant Admin** with a fresh temporary password (`Str::password(20)`, stored bcrypt-hashed), `is_tenant_admin = true`, `must_change_password = true`, `primary_branch_id = Default Branch` | tenant |
| 6 | Set status to **`ready`** and `provisioned_at = now()`. **Only now** is the subdomain reachable | central |
| — | Return a `ProvisioningResult` (tenant, admin email, temporary password) | memory only |

**On failure, at any of steps 3–5:**
- Tenant context is always ended. stancl's `Tenant::run()` has no `try/finally`, so the action manages initialization and cleanup itself.
- The tenant is marked **`failed`**, and a short `provisioning_error` (exception class and message) is stored.
- The failure is logged with only the tenant ID, the exception class and the message, never a stack trace with arguments.
- A `TenantProvisioningFailed` exception is thrown **without chaining** the original exception (see §10). The UI shows an error and the retry command.
- **Recovery:** `php artisan tenants:provision <subdomain>` resumes from the start. Every step tolerates work that is already done. Because the earlier temporary password was never shown, a new one is generated and displayed once.

---

## 8. Automatic Default Branch

- Every tenant gets exactly one branch at provisioning: **`MAIN` / "Main Branch"**, `is_default = true`.
- `users.primary_branch_id` is a **non-null foreign key** to `branches` with `restrictOnDelete`, so **every employee belongs to one primary branch**.
- `User::primaryBranch()` and `Branch::employees()` relations exist, so later milestones can add branch-based permissions.
- **Tenant Admins cannot create branches.** `BranchPolicy::create()` always returns `false`, and no route or UI exists for creating branches. Both are covered by tests.

---

## 9. Authentication

| | Platform Admin | Tenant user (incl. Tenant Admin) |
|---|---|---|
| Where | `localhost/admin/login` | `{sub}.localhost/login` |
| Guard | `platform` (session) | `web` (session, Fortify) |
| Provider / table | `platform_admins` in **central** DB | `users` in the **tenant** DB |
| Model | `App\Models\PlatformAdmin` (`CentralConnection`) | `App\Models\User` |
| Throttling | 5 failures per email and IP (`platform-login|…` key) | Fortify `login` limiter, key **scoped by tenant ID** + email + IP |
| Creation | `php artisan platform:create-admin` | Initial admin created by provisioning |
| Redirects | guests → `platform.login`; authenticated → `platform.tenants.index` | guests → tenant `login`; authenticated → `dashboard` |

**Temporary password and first login:**
1. The Platform Admin sees the temporary password once, on the provisioning result page.
2. The Tenant Admin logs in at the tenant URL. `EnsurePasswordIsChanged` (alias `password.changed`) redirects every protected tenant page to `/settings/security`, which shows a warning callout.
3. Changing the password through the Starter Kit security page sets `must_change_password = false`.

---

## 10. Security Measures and Tenant Isolation

| Threat | Mitigation | Verified by |
|---|---|---|
| Tenant A reads tenant B's data | Separate database per tenant; tenant models use the tenant connection | `TenantIsolationTest` (per-tenant user lists, connection switch) |
| Company A credentials used on company B | Authentication runs against the tenant database identified by the subdomain | `TenantIsolationTest` (A's credentials rejected on B; same email with different passwords) |
| Session replay across tenants | Host-only cookies; sessions stored **in each tenant DB**; `ScopeSessions` runs **before** `auth` and aborts with 403 on a mismatch | `TenantIsolationTest` (replay with colliding user IDs; asserts no user is resolved) + real-server smoke test |
| Platform session used on a tenant, or tenant session on the platform | Separate guards, tables and session scopes; central routes bound to the central domain | `TenantIsolationTest`, `PlatformAuthenticationTest` |
| Platform area reached from a tenant host | Central routes are domain-constrained (404 on tenant hosts) | `PlatformAuthenticationTest` |
| Tenant routes reached from the central host | `PreventAccessFromCentralDomains` (404) | `TenantIdentificationTest` |
| Half-provisioned tenant used | `EnsureTenantIsReady` (404 unless `ready`) | `TenantIdentificationTest` |
| Login lockout across tenants | Tenant ID included in Fortify's throttle key | `TenantIsolationTest` |
| Temporary password leaked | Never persisted in plain text, never flashed to the session, never logged; the result page is `no-store`; `#[\SensitiveParameter]` and a redacted `__debugInfo()` on `ProvisioningResult`; the failure exception is not chained (because `zend.exception_ignore_args=Off` on this machine, traces would include string arguments) | `TenantProvisioningTest` (log listener, session and dump checks) + smoke test (`grep -F` on `storage/logs`) |
| Hardcoded credentials | None; platform admins are created interactively | Code review |
| Public registration | Fortify `registration` feature disabled | Baseline inspection |
| App touching other projects on the shared MySQL server | Dedicated MySQL user restricted to `fcl\_%` (see §14) | Verified grants and denied probes |

---

## 11. Installed Packages

| Package | Version | Notes |
|---|---|---|
| `stancl/tenancy` | **v3.10.1** | Declares `illuminate/support ^10\|^11\|^12\|^13`; resolved cleanly; Composer reported no advisories |
| `stancl/jobpipeline` | v1.9.0 | dependency |
| `stancl/virtualcolumn` | v1.5.0 | dependency |
| `facade/ignition-contracts` | 1.0.2 | dependency |

Existing stack (unchanged): PHP 8.4.21, Laravel 13.37.0, Livewire 4.4.7, Flux 2.20.1, Fortify 1.41.0, Pest 5.3.3, Larastan 3.13.0, MySQL 8.4.3 (Laragon).

**npm:** `package.json` gained an `overrides` entry, `"tinypool": "2.1.2"`, which patches the vulnerable transitive dependency of `vite-plus` → `oxfmt`. The lockfile change is limited to the `tinypool` entry (3 lines). `vite` (8.3.4) and `vite-plus` (0.3.0) are unchanged. See §16, item 1.

---

## 12. Migrations

**Central** (`database/migrations`, run with `php artisan migrate`):

| File | Status | Contents |
|---|---|---|
| `0001_01_01_000001_create_cache_table.php` | unchanged | cache, cache_locks |
| `0001_01_01_000002_create_jobs_table.php` | unchanged | jobs, job_batches, failed_jobs |
| `2019_09_15_000010_create_tenants_table.php` | new (stancl) + modified | `id` (uuid string), `name`, `status` (indexed), `provisioning_error`, `provisioned_at`, timestamps, `data` JSON |
| `2019_09_15_000020_create_domains_table.php` | new (stancl) | `domain` (unique), `tenant_id` FK (cascade) |
| `2026_10_19_104300_create_platform_admins_table.php` | new | name, email (unique), password, remember_token |
| `2026_10_19_104301_create_sessions_table.php` | new | central sessions |
| `0001_01_01_000000_create_users_table.php` | **moved** to tenant migrations | — |

**Tenant** (`database/migrations/tenant`, run per tenant by provisioning or `php artisan tenants:migrate`):

| File | Contents |
|---|---|
| `0001_01_01_000000_create_branches_table.php` | name, `code` (unique, 20), `is_default` |
| `0001_01_01_000001_create_users_table.php` | Starter Kit users + `primary_branch_id` (FK, restrict), `is_tenant_admin`, `must_change_password`; `password_reset_tokens`; `sessions` |

**Executed during this milestone:**
- `php artisan migrate` on the new, empty development database `fcl_central`: all 6 central migrations ran.
- The test suite migrates `fcl_central_testing` and creates and drops disposable `fcl_test_tenant_*` databases.
- No existing database was modified, refreshed or wiped.

---

## 13. Important Files

| Path | Responsibility |
|---|---|
| `app/Actions/Tenancy/ProvisionTenant.php` | Provisioning workflow (§7) |
| `app/Actions/Tenancy/ProvisioningResult.php` | One-time result DTO; password redacted from dumps |
| `app/Exceptions/TenantProvisioningFailed.php` | Provisioning failure (not chained, deliberately) |
| `app/Enums/TenantStatus.php` | `pending`, `provisioning`, `ready`, `failed` |
| `app/Models/Tenant.php` | Central tenant model (custom columns, `domains()`, `isReady()`, `url()`) |
| `app/Models/PlatformAdmin.php` | Central platform admin (`platform` guard) |
| `app/Models/Branch.php`, `app/Models/User.php` | Tenant models |
| `app/Policies/BranchPolicy.php` | `create()` always denied |
| `app/Http/Controllers/Platform/*` | Platform login/logout and tenant list/create/store |
| `app/Http/Requests/Platform/*` | Platform login (with throttling) and tenant creation validation |
| `app/Http/Middleware/EnsureTenantIsReady.php` | 404 for tenants that are not ready |
| `app/Http/Middleware/EnsurePasswordIsChanged.php` | Forced password change |
| `app/Session/TenantAwareDatabaseSessionHandler.php` | Resolves the session DB connection lazily (§16, item 4) |
| `app/Console/Commands/CreatePlatformAdmin.php` | `platform:create-admin` |
| `app/Console/Commands/ProvisionTenantCommand.php` | `tenants:provision` |
| `app/Providers/TenancyServiceProvider.php` | Tenancy events, middleware priority, 404 on unknown tenant, Livewire update route |
| `app/Providers/AppServiceProvider.php` | Registers the tenant-aware session handler |
| `app/Providers/FortifyServiceProvider.php` | Tenant-scoped login throttle key |
| `bootstrap/app.php` | `tenant` middleware group, priority rules, guest/user redirects, tenant route loading |
| `config/tenancy.php`, `config/auth.php`, `config/fortify.php`, `config/database.php` | See §4–§6, §9 |
| `routes/web.php` / `routes/tenant.php` / `routes/settings.php` | Central / tenant / tenant settings routes |
| `resources/views/layouts/platform.blade.php`, `resources/views/platform/**` | Minimal platform UI (Flux) |
| `resources/views/pages/settings/⚡security.blade.php` | Clears `must_change_password`; temporary-password notice |
| `tests/TestCase.php`, `tests/Pest.php` | Guarded test database lifecycle; `provisionTenant()` and `actingInTenant()` helpers |

---

## 14. Environment Configuration

There are **no secrets in this document**. `.env` is git-ignored, and `.env.example` has placeholders only.

```dotenv
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fcl_central
DB_USERNAME=fcl_app
DB_PASSWORD=            # set locally, never committed

CENTRAL_DOMAIN=localhost          # tenants are served on {subdomain}.CENTRAL_DOMAIN
TENANCY_DB_PREFIX=fcl_tenant_     # tenant database = prefix + tenant UUID

SESSION_DRIVER=database           # central sessions → fcl_central, tenant sessions → tenant DB
CACHE_STORE=database              # pinned to the central connection
QUEUE_CONNECTION=database         # pinned to the central connection
```

**Dedicated MySQL user (local development).** The local MySQL server is shared with other projects, and its `root` account has no password. So the app uses its own account:

```sql
CREATE USER 'fcl_app'@'127.0.0.1' IDENTIFIED BY '<generated>';
GRANT ALL PRIVILEGES ON `fcl\_%`.* TO 'fcl_app'@'127.0.0.1';
```

- **Verified grants:** `USAGE ON *.*` plus `ALL PRIVILEGES ON fcl\_%.*`.
- **Verified probes:** creating `fcl_central` and `fcl_central_testing` was allowed. Creating `fclx_probe` and `other_probe` was denied, as were reading `laravel.migrations` and creating a table in `lclerp`.
- **The `_` must be escaped** (`fcl\_%`). Unescaped, `_` is a single-character wildcard.
- **MySQL 8.4 note:** wildcards in database-level grants are deprecated (since 8.2) but still honored while `partial_revokes = OFF`, which is the case here. No other mechanism limits `CREATE DATABASE` to a name pattern; the alternative, a global `CREATE` grant, would reach every database. If `partial_revokes` is ever enabled, this grant must be revisited.

**Testing** (`phpunit.xml`): `DB_DATABASE=fcl_central_testing`, `TENANCY_DB_PREFIX=fcl_test_tenant_`, `CENTRAL_DOMAIN=localhost`, `APP_URL=http://localhost`. The credentials come from `.env`.

---

## 15. Automated Tests

**Commands:** `php artisan test --compact`, or the full project gate, `composer test`.

**Latest result:** **74 tests: 73 passed, 1 skipped, 0 failed, 218 assertions (~18s).** The skipped test is the Starter Kit 2FA test, skipped because Fortify 2FA is disabled. This was also the case at baseline.

| File | Tests | Covers |
|---|---|---|
| `Platform/PlatformAuthenticationTest` | 7 | Platform login, invalid password, lockout, guest redirect, logout, platform area unreachable from tenant hosts |
| `Platform/CreatePlatformAdminCommandTest` | 2 | Admin creation (email lowercased, password hashed); short password rejected |
| `Tenancy/TenantProvisioningTest` | 12 | Full provisioning (central record, domain, database exists, migrations, Default Branch, Tenant Admin, hashed temporary password); no tenant tables in central; password not in logs, session or dumps; guests refused; required fields; invalid, reserved and duplicate subdomains; **failure → `failed` → resume via command**; no re-provisioning of ready tenants |
| `Tenancy/TenantIdentificationTest` | 10 | Subdomain resolution of 2 tenants; **middleware priority order**; unknown subdomain 404; not-ready tenants 404 (pending, provisioning, failed); tenant routes 404 on central |
| `Tenancy/TenantIsolationTest` | 11 | Per-tenant databases and users; connection switching; cross-tenant credentials; same email in 2 tenants; **session replay (colliding user IDs)**; **tenant sessions stored in tenant DB**; per-tenant throttling; platform and tenant credentials and sessions isolated both ways |
| `Tenancy/BranchManagementTest` | 4 | Policy denies branch creation (admin and employee); no branch endpoint; employee ↔ primary branch |
| `Tenancy/TemporaryPasswordTest` | 6 | Login with temporary password on subdomain; forced redirect (3 pages); notice shown; password change lifts restriction |
| Starter Kit tests (Auth, Dashboard, Settings, Example) | 21 + 1 unit | Adapted to run inside a provisioned tenant (`actingInTenant()`); the central home page test now targets `central.home` |

**Test database safety** (`tests/TestCase.php`):
- `createApplication()` **refuses to boot** unless the environment is `testing`, the central database is exactly `fcl_central_testing` and the tenant prefix is exactly `fcl_test_tenant_`. This check runs before `RefreshDatabase` touches anything.
- Only the central `mysql` connection is wrapped in a transaction.
- Tenant databases are recorded when the test that creates them fires `DatabaseCreated`. After each test, each one is dropped only if the environment is `testing`, its name matches `^fcl_test_tenant_<uuid>$` **and** the current test created it.
- After every full run, `SHOW DATABASES LIKE 'fcl%'` shows only `fcl_central` and `fcl_central_testing`.

**Other checks:**
- **`composer test`** (config clear, `pint --test`, `phpstan analyse` at level 7, `php artisan test`): **passes, exit code 0.** It did not pass at baseline (see §16, item 8).
- `vendor/bin/phpstan analyse`: **0 errors.**
- `npm audit`: **0 vulnerabilities.** `npm run build`: succeeds.
- **Real-server smoke test.** The built-in PHP server was run against the *testing* databases with real cookies, then cleaned up. Results:
  - central `/` returned 200, and `/login` on central returned 404
  - platform login worked, and 2 tenants were provisioned (`no-store` header present)
  - the tenant login page returned 200, and admin login led to the forced redirect to `/settings/security` (200)
  - demo's credentials were rejected on demo2
  - **demo's session cookie replayed on demo2 got a fresh guest session** (302 to login)
  - the platform cookie on a tenant redirected to the tenant login
  - the admin area on a tenant returned 404, and an unknown subdomain returned 404
  - sessions were stored in the respective tenant databases, and the temporary password was absent from `storage/logs` (fixed-string search)

---

## 16. Issues Found and How They Were Resolved

1. **npm audit: 3 critical findings in dev tooling.** The chain was `vite-plus@0.3.0` → `oxfmt` → `tinypool<=2.1.1` (GHSA-5gmw-xhrv-c9v3, GHSA-85c8-ppgw-ccpr), all build-time only. The approved fix, pinning `vite-plus` to 0.3.3, made the audit clean, but `npm run build` then **failed**: 0.3.3 requires `vite` to be aliased to `@voidzero-dev/vite-plus-core` (`vp migrate`), which would replace the `vite` package. That went beyond the approved patch bump, so the change was **reverted** and the build restored. **Resolution:** an npm override, `"overrides": { "tinypool": "2.1.2" }` (patch release, same major version), was approved and applied.
   - `npm ls` shows `oxfmt@0.64.0 → tinypool@2.1.2 overridden`, and `vite` is unchanged.
   - `npm audit` reports **found 0 vulnerabilities**, and `npm run build` succeeds.
   - `oxfmt`, the package that depends on tinypool, was smoke-tested formatting a file on its multi-threaded path.
   - `npm audit fix --force` was never used.
2. **Risky baseline test.** `SecurityTest` › "two factor authentication disabled when confirmation abandoned between requests" had an empty body, left behind when `laravel/chisel` stripped 2FA. The empty stub (and an empty `beforeEach`) was removed. The suite now reports no risky tests.
3. **Implicit commits from `CREATE DATABASE`.** Solved with the separate `tenant_template` connection (§4).
4. **Tenant sessions were written to the central database.** Fortify's login controller injects the session guard while the router gathers middleware, before any route middleware runs. That created the session store, bound to the central connection, before tenancy initialized. It was found by the real-server smoke test, because unit tests use the `array` session driver. **Fix:** `TenantAwareDatabaseSessionHandler` resolves the connection on each read and write. A regression test using the database driver fails without the fix and passes with it.
5. **Middleware ordering.** Laravel's priority sorting placed `auth` before `ScopeSessions` and `EnsureTenantIsReady`. Without the fix, a replayed session made the guard resolve a user with the same ID in the *other* tenant. The request was still aborted with 403 by `ScopeSessions` before any controller ran, so it was not exploitable on these routes. **Fix:** both middleware were added to the priority list. The regression test asserts that no user is authenticated during a replayed request; it fails without the fix.
6. **Second HTTP kernel.** While fixing static-analysis findings, the provider briefly resolved the concrete `Foundation\Http\Kernel`, which is not bound, so the container built a throwaway kernel and stancl's priority entries were lost. Every tenant request then returned 404 on a real server. The smoke test caught this, not the suite, because tests resolve the kernel in a different order. **Fix:** resolve the contract binding. A middleware-order test now fails on this regression.
7. **stancl `Tenant::run()` has no `try/finally`.** If the callback throws, the request stays in tenant context. Provisioning therefore manages initialization and cleanup itself.
8. **Two pre-existing PHPStan errors** made `composer test` fail at baseline:
   - **`UserFactory::withTwoFactor()` had no body** (stripped by `laravel/chisel`), so it would throw a `TypeError` if called. It was restored to the Starter Kit's state definition (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`), which matches the attributes documented on `User`. It is only used by the 2FA test, which stays skipped while 2FA is disabled.
   - **`App\Concerns\ProfileValidationRules` reported as unused.** This is a **false positive**: the trait *is* used by the Livewire single-file component `resources/views/pages/settings/⚡profile.blade.php`, which PHPStan does not analyse. There is no code defect, so `phpstan.neon` has a narrowly scoped `ignoreErrors` entry (identifier `trait.unused`, that file only) with a comment explaining why. This is a configured exception, not a code fix.

---

## 17. Remaining Limitations and Known Problems

- **npm override maintenance:** the `tinypool` override should be removed once `vite-plus` is upgraded through its supported migration path (`vp migrate`, which re-aliases `vite`). That upgrade was deliberately not done in this milestone.
- **PHPStan exception:** the scoped `trait.unused` ignore for `ProfileValidationRules` should be removed if PHPStan later learns to analyse Livewire single-file components, or if the trait gains a PHP consumer.
- **Cache is not tenant-scoped.** `CacheTenancyBootstrapper` is disabled because the `database` cache store has no tag support. No tenant data is cached in this milestone; switch to Redis before caching tenant data.
- **Provisioning runs synchronously** in the web request. That is acceptable for manual, low-volume provisioning; move it to a queued job for production scale.
- **No tenant deletion, suspension or renaming,** and failed tenants can only be resumed, not cleaned up, from the app. Partially provisioned databases are kept for inspection by design.
- **Local subdomains** rely on browsers resolving `*.localhost` to `127.0.0.1`. Chrome, Edge and Firefox do; some tools, and the Windows hosts file, do not support wildcards (§18).
- **Password reset emails** use the `log` mail driver locally, so reset links appear in `storage/logs/laravel.log`. This is Starter Kit behavior; temporary passwords are never logged.
- **Livewire-driven password change** was verified by feature tests, not in a real browser; the smoke test used curl.
- **Two-factor authentication** is disabled, as in the Starter Kit baseline. The `users` table has no `two_factor_*` columns; enabling 2FA requires adding them.

---

## 18. Local Setup and Manual Verification

**Prerequisites:** PHP 8.4, Composer, Node, and MySQL 8.4 running on `127.0.0.1:3306` with the `fcl_app` user (§14).

```bash
composer install
npm install && npm run build
cp .env.example .env            # then set DB_PASSWORD for fcl_app; php artisan key:generate if new
php artisan migrate             # central migrations on fcl_central
php artisan platform:create-admin
php artisan serve               # http://localhost:8000
```

**Manual walkthrough (Chrome, Edge or Firefox; no hosts-file changes needed for `*.localhost`):**
1. Open `http://localhost:8000` and confirm the welcome page has a "Platform admin log in" link.
2. Open `http://localhost:8000/login` and expect **404**, because tenant login does not exist on the central host.
3. Log in at `http://localhost:8000/admin/login` with the platform admin you created.
4. Click **New tenant** and create company `Acme Freight` with subdomain `acme` and an admin email. Copy the **temporary password**; reloading will not show it again.
5. Repeat for `Globex Logistics` with subdomain `globex`.
6. Confirm that both appear as **Ready** in the tenant list.
7. Open `http://acme.localhost:8000/login` in a private window and log in with the Acme admin and its temporary password. You are redirected to **Settings → Security** with a warning. Change the password, then open the dashboard.
8. Open `http://globex.localhost:8000/login` and try Acme's credentials. Expect **"These credentials do not match our records."**
9. Open `http://acme.localhost:8000/admin/login` and expect **404**.
10. Open `http://unknown.localhost:8000/login` and expect **404**.
11. Optional, to inspect the databases (as a MySQL admin): `SHOW DATABASES LIKE 'fcl%';` shows `fcl_central` and one `fcl_tenant_<uuid>` per tenant.

**If `*.localhost` doesn't resolve** (some tools, older browsers): add one line per tenant to `C:\Windows\System32\drivers\etc\hosts`, for example `127.0.0.1 acme.localhost` (the file has no wildcard support). Alternatively, use a local DNS tool such as Acrylic DNS Proxy for a wildcard.

**Automated verification:** `php artisan test --compact`.

---

## 19. Deferred to Future Milestones (explicitly out of scope)

- FCL Import operations: shipments, containers, customs, documentation
- Subscription billing, payment gateways, proration, seat management
- User impersonation
- Roles and permissions beyond the `is_tenant_admin` flag; branch-based permissions
- Additional branch creation or branch management
- Employee (non-admin user) management UI
- Tenant suspension, deletion and domain changes
- Dashboards and UI redesign

---

## 20. Recommended Next Milestone

**Milestone 2: Tenant Administration & Access Control**
1. Tenant Admin manages employees (invite or create, deactivate), each assigned a primary branch.
2. Roles and permissions, for example `spatie/laravel-permission` installed in tenant databases, with branch-scoped permission checks built on `primary_branch_id`.
3. Platform-side tenant lifecycle: suspend or reactivate, view provisioning errors, cleanup of failed tenants with explicit confirmation.
4. Queue tenant provisioning (Redis), and enable `CacheTenancyBootstrapper` with a tag-capable cache.
5. Upgrade `vite-plus` through `vp migrate` and drop the temporary `tinypool` override.
