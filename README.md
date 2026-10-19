# FCL SaaS Platform

A commercial, multi-tenant SaaS application for freight forwarding companies, built with Laravel.

This README tells a developer who has just received the project how to install it, configure the databases, create the first administrator and run the central platform and the tenant applications locally. Details on each milestone are in [`docs/`](docs/).

> **Status:** Milestones 1–4 are complete (multi-tenant foundation, tenant users and permissions, central platform management, subscriptions and billing). FCL business operations (shipments, containers, customs) are not implemented yet.

---

## Table of Contents

- [A. Project Overview](#a-project-overview)
- [B. System Requirements](#b-system-requirements)
- [C. Getting the Project](#c-getting-the-project)
- [D. Install Dependencies](#d-install-dependencies)
- [E. Environment Configuration](#e-environment-configuration)
- [F. MySQL Database Setup](#f-mysql-database-setup)
- [G. Initial Platform Admin Setup](#g-initial-platform-admin-setup)
- [H. Starting the Application](#h-starting-the-application)
- [I. Application URLs](#i-application-urls)
- [J. Creating and Provisioning a Tenant](#j-creating-and-provisioning-a-tenant)
- [K. Subscription Activation](#k-subscription-activation)
- [L. Tenant Employees and Permissions](#l-tenant-employees-and-permissions)
- [M. Running Tests](#m-running-tests)
- [N. Common Errors and Troubleshooting](#n-common-errors-and-troubleshooting)
- [O. Project Structure](#o-project-structure)
- [P. Important Notes](#p-important-notes)
- [Q. Quick Start — Daily Development](#q-quick-start--daily-development)

---

## A. Project Overview

The application has two parts that share one codebase:

| | Central Platform | Tenant Application |
|---|---|---|
| Who uses it | Platform Admins (the SaaS operator) | One customer company: its Tenant Admins and employees |
| URL | `http://localhost:8000/admin` | `http://{subdomain}.localhost:8000` |
| Database | The central database, `fcl_central` | The company's own database, `fcl_tenant_<tenant-uuid>` |
| Login | `platform` guard, `platform_admins` table | `web` guard (Laravel Fortify), `users` table in the tenant database |
| Routes | `routes/web.php` | `routes/tenant.php`, `routes/settings.php` |

- **Database per tenant.** Each company (tenant) gets its own MySQL database. The central database holds only platform data: tenants, domains, Platform Admins, plans, subscriptions, billing and the audit trail.
- **Subdomain per tenant.** The subdomain identifies the tenant, for example `acme.localhost`. The request then runs against that tenant's database.
- **Separate authentication.** Platform Admin accounts and tenant user accounts are completely separate. A login on one never grants access to the other.

**Main technologies:** PHP 8.4, Laravel 13, Livewire 4, Flux UI 2, Laravel Fortify, Tailwind CSS 4, Vite (through `vite-plus`), MySQL 8.4, [`stancl/tenancy`](https://tenancyforlaravel.com/) v3 (multi-database tenancy), `spatie/laravel-permission` v8 (per-tenant roles), `brick/math` (exact billing arithmetic), Pest 5, PHPStan (Larastan) and Laravel Pint.

---

## B. System Requirements

| Software | Requirement | Verified with |
|---|---|---|
| PHP | `^8.3` according to `composer.json`; **8.4 recommended** (CI and development use 8.4) | 8.4.21 |
| Laravel | Installed by Composer (`laravel/framework ^13.17`) | 13.37.0 |
| Composer | Composer 2 | 2.9.7 |
| Node.js | `^20.19` or `>=22.12` (required by Vite 8); CI uses Node 22 | 26.10.0 |
| npm | Comes with Node.js | 11.19.1 |
| MySQL | MySQL 8. **SQLite is not supported:** the test suite refuses to run on anything other than MySQL, and billing uses MySQL named locks. | 8.4.3 (Laragon) |

**PHP extensions.** Composer needs `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session` and `tokenizer`. The application also needs **`pdo_mysql`** to talk to MySQL. Check what your PHP is missing with:

```powershell
composer check-platform-reqs
```

**Windows users:** [Laragon](https://laragon.org/) is the environment the project is developed on. It provides PHP, MySQL, Composer and Node.js in one install. Make sure the PHP version selected in Laragon is 8.4 and that `php`, `composer`, `node` and `npm` work in a new terminal.

A browser that resolves `*.localhost` to your own machine is required for tenant subdomains. Chrome, Edge and Firefox do this automatically; see [Section I](#i-application-urls).

---

## C. Getting the Project

1. **Get the code.**
   - If you were given a Git remote URL, clone it:
     ```powershell
     git clone <repository-url> "FCL SaaS Platform"
     ```
   - If you received the project folder directly (for example, a ZIP or a copy), put it somewhere convenient, for example `C:\Users\<you>\Desktop\FCL SaaS Platform`. If it contains `vendor/` or `node_modules/` from another machine, you can delete them and reinstall (Section D).
2. **Open it in VS Code:** *File → Open Folder…* and choose the project folder.
3. **Open a terminal in the project folder:** *Terminal → New Terminal* (`` Ctrl+` ``). The terminal opens in the project root, where `artisan` and `composer.json` are. All commands in this README run from there.

---

## D. Install Dependencies

Run these the first time, and again whenever `composer.lock` or `package-lock.json` changes (for example after pulling new commits).

```powershell
composer install
npm ci
```

- `composer install` installs exactly the PHP package versions in `composer.lock`.
- `npm ci` installs exactly the versions in `package-lock.json` and removes anything else from `node_modules`. Use it instead of `npm install`, which may rewrite the lockfile.
- The project's `.npmrc` sets `ignore-scripts=true` (packages' install scripts don't run) and `audit=true`.
- The `optionalDependencies` in `package.json` are Linux binaries for CI; npm skips them on Windows.

Do **not** run `composer update`, `npm update` or `npm audit fix --force`. They change dependency versions, which is a project decision, not a setup step.

> `composer.json` also defines a `composer setup` script (install, copy `.env`, generate key, migrate, `npm install`, `npm run build`). It is used by CI. For a first local setup, follow the steps in this README instead, because the database must be prepared first (Section F).

---

## E. Environment Configuration

### 1. Create `.env`

```powershell
Copy-Item .env.example .env
```

`.env` is git-ignored. Never commit it.

### 2. Generate the application key

```powershell
php artisan key:generate
```

Run this once, on a new `.env` only. Regenerating the key on an existing installation logs everyone out and makes encrypted values unreadable.

### 3. Set the values

`.env.example` already contains the right structure. These are the settings that matter for this project:

```dotenv
APP_NAME="FCL SaaS Platform"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000      # must include the port you serve on (see below)

# Central database connection (also the template for tenant databases)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fcl_central
DB_USERNAME=fcl_app
DB_PASSWORD=<your-local-fcl_app-password>

# Tenancy
CENTRAL_DOMAIN=localhost           # tenants are served on {subdomain}.CENTRAL_DOMAIN
TENANCY_DB_PREFIX=fcl_tenant_      # tenant database name = prefix + tenant UUID

# Sessions, cache and queue (keep these as they are)
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=null
CACHE_STORE=database
QUEUE_CONNECTION=database

# Mail is written to storage/logs/laravel.log locally
MAIL_MAILER=log
```

What each group does:

| Setting | Explanation |
|---|---|
| `APP_URL` | Used to build tenant URLs such as `http://acme.localhost:8000/login` (scheme and **port** are taken from it). If you serve on another port, change it here too. |
| `DB_*` | The **central** connection. The tenant connection is built from the same host, port, username and password; only the database name changes per tenant. So the MySQL user must be allowed to create and use databases matching the tenant prefix (Section F). |
| `CENTRAL_DOMAIN` | The host of the central platform. Central routes exist only on this host, and tenants are `{subdomain}.CENTRAL_DOMAIN`. Keep `localhost` for local development. |
| `TENANCY_DB_PREFIX` | Prefix of every tenant database. Keep `fcl_tenant_` so tenant databases match the MySQL grant in Section F. |
| `SESSION_DRIVER=database` | Central sessions are stored in `fcl_central`; tenant sessions are stored in each tenant's own database. |
| `SESSION_DOMAIN=null` | **Keep it `null`.** Session cookies must be host-only so a session on `acme.localhost` is never sent to `globex.localhost` or to the central platform. Do not set it to `.localhost`. |
| `CACHE_STORE`, `QUEUE_CONNECTION` | Both use the central database. No feature currently requires a running queue worker. |
| `MAIL_MAILER=log` | Password reset emails are written to `storage/logs/laravel.log` instead of being sent. |

Optional settings supported by the code (the defaults are fine): `BILLING_SEAT_LOCK_TIMEOUT` (seconds an employee creation waits for another seat operation of the same tenant, default `5`) and `PERMISSION_CACHE_STORE` (default `array`; leave it, see `docs/MILESTONE_02_…` §5).

Never put real passwords, tokens or production values in `.env.example` or in documentation.

---

## F. MySQL Database Setup

### How the databases are organized

| Database | Created by | Contains |
|---|---|---|
| `fcl_central` | You, once (below) | Tenants, domains, Platform Admins, central sessions, cache, jobs, plans, subscriptions, billing, audit trail |
| `fcl_tenant_<tenant-uuid>` | **The application**, automatically, when a Platform Admin creates a company | That company's branches, users, roles, permissions and sessions |
| `fcl_central_testing` | You, once, only if you run the tests | The central database used by the automated tests |
| `fcl_test_tenant_<uuid>` | The test suite, temporarily | Disposable tenant databases, dropped after each test |

There is deliberately **no `users` table in the central database**: tenant users exist only in tenant databases.

### Required MySQL permissions

The application must be able to run `CREATE DATABASE` for each new tenant. Instead of using an unrestricted `root` account, create a dedicated user that can only touch databases whose names start with `fcl_`.

Run this **once**, as a MySQL administrator (in Laragon: *Database* button → HeidiSQL, or the `mysql` client from the Laragon terminal). Replace the placeholder with a password you generate:

```sql
-- A dedicated application user, allowed to connect from the local machine only.
CREATE USER 'fcl_app'@'127.0.0.1' IDENTIFIED BY '<strong-generated-password>';

-- Full rights, but only on databases named fcl_... (central, testing and every tenant).
-- The underscore must be escaped: unescaped, "_" is a single-character wildcard.
GRANT ALL PRIVILEGES ON `fcl\_%`.* TO 'fcl_app'@'127.0.0.1';

-- Create the central database (and the testing database if you will run tests).
CREATE DATABASE fcl_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE fcl_central_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then put the same password in `.env` as `DB_PASSWORD`.

Notes:
- `DB_HOST=127.0.0.1` connects over TCP from `127.0.0.1`, which matches the user's host part above.
- On MySQL 8.2+, wildcards in database-level grants are deprecated but still honored while `partial_revokes` is `OFF` (the default). If your server has `partial_revokes = ON`, this grant must be revisited.
- Because `fcl_app` can create `fcl_…` databases, you can also let `php artisan migrate` create `fcl_central`: when the database is missing, Laravel offers to create it.
- Verify the grant with `SHOW GRANTS FOR 'fcl_app'@'127.0.0.1';` — you should see `USAGE ON *.*` and `ALL PRIVILEGES ON fcl\_%.*`.

### Fresh development environment

1. Create the MySQL user and databases (above).
2. Configure `.env` (Section E).
3. Run the **central** migrations:
   ```powershell
   php artisan migrate
   ```
   This creates every central table in `fcl_central`. It does not touch tenant databases.
4. Create the first Platform Admin (Section G).

There is nothing to do for tenant databases: they are created and migrated automatically when you create a company (Section J).

### Existing databases (an installation that already has data)

When you pull new code onto an installation that already has `fcl_central` and tenant databases:

```powershell
php artisan migrate                       # new central migrations, if any
php artisan tenants:migrate               # new tenant migrations, applied to every tenant database
php artisan tenants:sync-authorization    # add new roles/permissions to existing tenants
```

- All three only apply what is missing and are safe to re-run.
- `tenants:migrate` runs the migrations in `database/migrations/tenant` against each tenant database. Provisioning runs them automatically for new tenants, so you only need it after pulling new tenant migrations.
- `tenants:sync-authorization` accepts `--tenant=<id-or-subdomain>` (repeatable) to limit it to specific tenants.
- To preview central schema changes first: `php artisan migrate --pretend`. To see what has run: `php artisan migrate:status`.

### Commands that destroy data — do not use

Do **not** run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `tenants:migrate-fresh` or `tenants:rollback` against a database you care about. They drop tables in the central or tenant databases, and every tenant's data lives only in its own database. If you really need a clean slate, check `DB_DATABASE` and the target first, and back up.

---

## G. Initial Platform Admin Setup

There is no seeded or default administrator. Create one with the project's Artisan command:

```powershell
php artisan platform:create-admin
```

It asks for the name, email address, password and password confirmation. You can pass the name and email as options; **the password is always entered interactively** and is never accepted on the command line:

```powershell
php artisan platform:create-admin --name="Your Name" --email="you@example.com"
```

- Passwords must be **at least 12 characters**.
- The email is stored lowercased and must be unique.
- Log in at **`http://localhost:8000/admin/login`**. After login you land on the platform dashboard at `http://localhost:8000/admin`.
- Login is rate limited: 5 failed attempts per email and IP lock it temporarily.
- Platform Admin accounts cannot log in to tenant subdomains, and tenant users cannot log in to `/admin`.

---

## H. Starting the Application

### Every day: what needs to run

| # | What | Where | Command |
|---|---|---|---|
| 1 | MySQL | Laragon | Click **Start All** (or start MySQL only) |
| 2 | Laravel server | Terminal 1 | `php artisan serve` |
| 3 | Vite (frontend assets) | Terminal 2 | `npm run dev` |

**1. Start MySQL.** Open Laragon and click **Start All**. Laragon's own web server is not needed; the application is served by `php artisan serve`.

**2. Terminal 1 — Laravel development server:**

```powershell
php artisan serve
```

It serves on `http://127.0.0.1:8000`, which also answers for `localhost:8000` and every `*.localhost:8000` tenant subdomain. Keep this terminal open.

**3. Terminal 2 — Vite dev server** (open a second terminal with the **+** button in VS Code's terminal panel):

```powershell
npm run dev
```

This runs `vp dev` (Vite through `vite-plus`) with hot reload of CSS, JavaScript and Blade views. Keep it open while you work.

Then open `http://localhost:8000/admin/login`.

### Alternative: one terminal

```powershell
composer dev
```

This runs `php artisan dev`, which starts `php artisan serve`, `php artisan queue:listen` and `npm run dev` together in one terminal (list them with `php artisan dev:list`). Stop everything with `Ctrl+C`.

### When to use `npm run build` instead

```powershell
npm run build
```

Use it when you **don't** want to keep a Vite dev server running, for example when you are only working on PHP, testing, or demoing. It compiles the assets once into `public/build/`. Then only `php artisan serve` is needed. Run it again after changing CSS or JavaScript.

If you stop `npm run dev` abnormally, a stale `public/hot` file can remain and make pages look for a Vite server that is no longer running (unstyled pages). Delete `public/hot`, or start `npm run dev` again.

---

## I. Application URLs

| Page | URL |
|---|---|
| Central welcome page (link to the platform login) | `http://localhost:8000/` |
| **Central Platform login** | `http://localhost:8000/admin/login` |
| **Central Platform dashboard** | `http://localhost:8000/admin` |
| Tenants (list / create) | `http://localhost:8000/admin/tenants`, `/admin/tenants/create` |
| Plans | `http://localhost:8000/admin/plans` |
| Subscriptions | `http://localhost:8000/admin/subscriptions` |
| Seat requests | `http://localhost:8000/admin/seat-requests` |
| Billing (charges and payments) | `http://localhost:8000/admin/charges` |
| **Tenant application** | `http://acme.localhost:8000/` (redirects to `/dashboard`) |
| **Tenant login** | `http://acme.localhost:8000/login` |
| Tenant dashboard | `http://acme.localhost:8000/dashboard` |
| Employees / Roles & Permissions | `http://acme.localhost:8000/employees`, `/roles` |
| Subscription & billing (tenant view) | `http://acme.localhost:8000/subscription` |
| Account settings | `http://acme.localhost:8000/settings/profile`, `/settings/security`, `/settings/appearance` |

`acme` is only an **example subdomain**. Use whatever subdomain you chose when you created the tenant.

**How `*.localhost` subdomains work.** Chrome, Edge and Firefox resolve every `something.localhost` name to `127.0.0.1`, so `http://acme.localhost:8000` reaches the same `php artisan serve` process. The application reads the subdomain from the `Host` header, finds the tenant in the central `domains` table and switches to that tenant's database. No hosts-file changes are needed in those browsers.

If a tool or browser does not resolve `*.localhost`, add one line per tenant to `C:\Windows\System32\drivers\etc\hosts` (edit as Administrator; the file has no wildcard support):

```text
127.0.0.1 acme.localhost
```

Expected behavior that is **not** an error:
- `http://localhost:8000/login` → **404**. Tenant login does not exist on the central host.
- `http://acme.localhost:8000/admin/login` → **404**. The platform area exists only on the central host.
- `http://unknown.localhost:8000/login` → **404**. No tenant has that subdomain.

---

## J. Creating and Provisioning a Tenant

1. Log in at `http://localhost:8000/admin/login`.
2. Go to **Tenants → New tenant** (`/admin/tenants/create`).
3. Enter the company name, the subdomain, and the name and email of the first Tenant Admin.
   - Subdomain: 3–40 characters, lowercase letters, digits and single hyphens (`acme`, `acme-freight`). It must be unique. Reserved names are refused: `admin, api, app, assets, central, dashboard, ftp, mail, platform, smtp, static, status, support, www`.
4. Submit. Provisioning runs immediately, in the request.

**What happens automatically** (`app/Actions/Tenancy/ProvisionTenant.php`):

| Step | Result |
|---|---|
| 1 | The tenant record (UUID) and its subdomain are saved in the central database, status `pending` |
| 2 | Status becomes `provisioning` |
| 3 | The database `fcl_tenant_<uuid>` is created |
| 4 | The tenant migrations (`database/migrations/tenant`) run on it |
| 5 | The **Default Branch** (`MAIN`, "Main Branch") is created, the permission catalog and the built-in roles are created, and the **first Tenant Admin** is created with a random temporary password |
| 6 | Status becomes `ready`. Only now does the subdomain respond |

5. The result page shows the tenant login URL, the admin email and the **temporary password, exactly once**. Copy it now. It is stored only as a hash, never logged, and cannot be displayed again.
6. Give the Tenant Admin the URL and the temporary password. At the first login at `http://<subdomain>.localhost:8000/login`, they are redirected to **Settings → Security** and must change the password before using anything else.

> A new tenant **requires a subscription** before its users can use the application. Continue with [Section K](#k-subscription-activation).

### Recovering from a failed provisioning

If a step fails, the tenant is marked `failed` and the tenant details page in the platform shows a sanitized error summary. Fix the cause (usually MySQL permissions, Section F), then resume:

```powershell
php artisan tenants:provision acme        # the subdomain or the tenant ID
```

- It resumes from the start and skips work that is already done. Nothing is dropped.
- Because the original temporary password was never shown, a **new one** is generated and printed once.
- A tenant that is already `ready` is not touched.
- There is no web "retry" button and no automatic cleanup of failed databases (by design).

To list tenants from the command line: `php artisan tenants:list`.

---

## K. Subscription Activation

The platform sells **annual subscriptions only**. Plans, subscriptions and billing are managed manually by Platform Admins; there is no payment gateway and no self-registration.

**Annual total = plan base fee + (purchased seats × annual seat price).** Every **active** user of the tenant, including Tenant Admins, uses one seat.

### Developer setup flow

1. **Create a plan** — Platform → **Plans → New plan** (`/admin/plans/create`): name, currency (from the allow-list in `config/billing.php`), annual base fee and annual seat price. No prices are configured in code; you enter them. The currency cannot be changed later.
2. **Create or select a tenant** — Section J.
3. **Activate its subscription** — Platform → **Subscriptions** (`/admin/subscriptions`), filter *No subscription*, click **Manage** → **Activate subscription**. Choose the plan, the number of seats and the start date (today or earlier), click **Preview**, then **Activate**. An initial charge is created.
4. **Assign purchased seats** — the seat count is chosen at activation. It must be at least the number of active users (the Tenant Admin already uses 1). Later increases are requested by the Tenant Admin from **Subscription & billing** and approved under Platform → **Seat requests**; they are charged prorated to the renewal date.
5. **Log in as Tenant Admin** at `http://<subdomain>.localhost:8000/login`. The dashboard, Employees and Roles pages are now available.
6. **Create employees** under **Employees**. The page shows "X of Y purchased seats in use". When no seat is free, creation is refused. Deactivating an employee frees the seat for reuse at no extra charge.

Payments are recorded manually by a Platform Admin on each charge (`/admin/charges`). Renewal opens 30 days before the term ends.

### Three separate concepts

| Concept | Controlled by | Effect on the tenant subdomain |
|---|---|---|
| **Provisioning status** (`pending`, `provisioning`, `ready`, `failed`) | Tenant creation / `tenants:provision` | **404** for everything until `ready` |
| **Manual suspension** (active / suspended) | Platform Admin: tenant details → *Suspend access* / *Reactivate access* | **403** for every page, including login, while suspended — even with a valid subscription |
| **Subscription validity** (legacy, none, active, expired) | Platform Admin: activate / renew | Without an active subscription, users can log in but operational pages redirect to **Subscription required** |

They are independent: activating or renewing a subscription never lifts a manual suspension, and suspending never changes the subscription.

### Why a new tenant shows "Subscription required"

Every tenant created after Milestone 4 starts with **no subscription** and **0 seats**. Its users can log in, change their password and see `/subscription`, but `/dashboard`, `/employees` and `/roles` redirect to `/subscription-required`, and no employees can be added. Activate a subscription (step 3) to unlock it. When a term ends without renewal, the tenant becomes *expired* and behaves the same way until it is renewed.

(Tenants that existed before Milestone 4 were marked **legacy**: unrestricted and without a seat limit until a subscription is activated for them.)

---

## L. Tenant Employees and Permissions

- **Default Branch.** Every tenant has one branch, `MAIN` ("Main Branch"), created during provisioning. Every user belongs to it. Tenant Admins cannot create branches; branch management is not implemented yet.
- **Tenant Admin role.** Holds every permission, cannot be edited or deleted, and can be granted only by another Tenant Admin. The last active Tenant Admin can never be demoted or deactivated.
- **Employee role.** Starts with no permissions. It is assigned by default to new employees. Its permissions can be edited; it cannot be renamed or deleted.
- **Custom roles** are created at `/roles` by users with `roles.manage`. Users can only grant permissions they hold themselves.
- **Permissions:** `employees.view`, `employees.create`, `employees.update`, `employees.deactivate`, `roles.view`, `roles.manage`, `roles.assign`, `billing.view`, `billing.manage`. Roles and permissions are stored in each tenant's own database.
- **Employee management** at `/employees`: create (a one-time temporary password is shown once, and the employee must change it at first login), edit, assign roles, deactivate and reactivate. Deactivation ends the employee's sessions immediately.
- **Seat restrictions.** Creating or reactivating an employee requires a free purchased seat (Section K). Legacy tenants have no limit.

**Upgrading existing tenants.** When new code adds tenant migrations or permissions, existing tenants must be brought up to date (new tenants get everything during provisioning):

```powershell
php artisan tenants:migrate
php artisan tenants:sync-authorization                  # every ready tenant
php artisan tenants:sync-authorization --tenant=acme    # or specific tenants
```

`tenants:sync-authorization` is idempotent: it creates missing permissions and roles, gives the Tenant Admin role every permission, and never resets a customized Employee role.

---

## M. Running Tests

> **The tests create and drop databases.** They use the central database `fcl_central_testing` (migrated and reset by the suite) and create temporary tenant databases named `fcl_test_tenant_<uuid>`, which are dropped after each test. Never point the test configuration at a production server, at `fcl_central`, or at any database you care about.

**Safety guard.** `phpunit.xml` sets `APP_ENV=testing`, `DB_DATABASE=fcl_central_testing` and `TENANCY_DB_PREFIX=fcl_test_tenant_`; the database host and credentials come from your `.env`. `tests/TestCase.php` **refuses to run** unless the environment is `testing`, the central database is exactly `fcl_central_testing` and the prefix is exactly `fcl_test_tenant_`. It only drops tenant databases that the current test created. Do not weaken these settings.

**Prerequisite:** the `fcl_central_testing` database exists and `fcl_app` can access it (Section F).

| Check | Command |
|---|---|
| Full project gate (config clear, Pint check, PHPStan, all tests) | `composer test` |
| Tests only (Pest) | `php artisan test --compact` |
| One file or one test | `php artisan test --compact tests/Feature/Billing/PricingTest.php` / `php artisan test --compact --filter="test name"` |
| Code style check (no changes) | `composer lint:check` |
| Code style fix | `composer lint` (runs `pint --parallel`) |
| Static analysis (PHPStan level 7) | `composer types:check` |
| Frontend build | `npm run build` |
| npm security audit | `npm audit` |

After a run, `SHOW DATABASES LIKE 'fcl%';` should show only `fcl_central`, `fcl_central_testing` and your own `fcl_tenant_…` databases. One test (the Starter Kit's tenant two-factor test) is skipped because Fortify two-factor authentication is disabled for tenant users.

Do not run `npm audit fix --force`: it changes dependency versions. The `tinypool` override in `package.json` is intentional (see `docs/MILESTONE_01_…` §16).

---

## N. Common Errors and Troubleshooting

| Problem | Likely cause | Solution |
|---|---|---|
| `SQLSTATE[HY000] [2002] … connection refused` / "No connection could be made" | MySQL is not running, or the host/port is wrong | Start MySQL in Laragon. Check `DB_HOST=127.0.0.1` and `DB_PORT` (default `3306`) in `.env`. |
| `SQLSTATE[HY000] [1045] Access denied for user 'fcl_app'` | Wrong password, or the user does not exist for host `127.0.0.1` | Check `DB_PASSWORD`. As MySQL admin, run `SHOW GRANTS FOR 'fcl_app'@'127.0.0.1';` and recreate the user if needed (Section F). |
| `SQLSTATE[HY000] [1049] Unknown database 'fcl_central'` | The central database was not created | Create it (Section F) or run `php artisan migrate` and accept the offer to create it. |
| `Access denied … to database 'fcl_tenant_…'` during tenant creation | The MySQL user cannot create tenant databases | Grant `ALL PRIVILEGES ON \`fcl\_%\`.*` (with the escaped underscore) and keep `TENANCY_DB_PREFIX=fcl_tenant_`. Then resume with `php artisan tenants:provision <subdomain>`. |
| `No application encryption key has been specified` | `APP_KEY` is empty | `php artisan key:generate` (only on a new `.env`). |
| `vendor/autoload.php … failed to open stream` or `Class … not found` | Composer dependencies are not installed or outdated | `composer install`. |
| `Vite manifest not found at public/build/manifest.json` | Assets were never built and Vite is not running | Run `npm run dev` in a second terminal, or `npm run build` once. |
| Pages are unstyled / CSS and JS fail to load | A stale `public/hot` file points to a stopped Vite server, or `node_modules` is missing | Start `npm run dev`, or delete `public/hot` and run `npm run build`. Run `npm ci` if `node_modules` is missing. |
| Tenant subdomain returns **404** | Wrong subdomain; the tenant is not `ready` (pending, provisioning or failed); or you opened a tenant URL on the central host / platform URL on a tenant host | Check the tenant's status at `/admin/tenants`. Resume failed tenants with `tenants:provision`. Use `http://<subdomain>.localhost:8000`, not `localhost:8000/login`. |
| Subdomain does not resolve at all (browser "site can't be reached") | The browser or tool doesn't resolve `*.localhost` | Use Chrome, Edge or Firefox, or add `127.0.0.1 <subdomain>.localhost` to the hosts file (Section I). |
| Tenant link opens the wrong port | `APP_URL` doesn't match the port you serve on | Set `APP_URL=http://localhost:8000` (or your port), then `php artisan config:clear`. |
| Tenant returns **403** "Access to this company account has been suspended" | A Platform Admin suspended the tenant | Reactivate it: Platform → tenant details → *Reactivate access*. |
| Tenant returns **403** right after switching between tenants | A session cookie from another tenant was sent (host-only cookie mismatch, e.g. `SESSION_DOMAIN` was changed) | Keep `SESSION_DOMAIN=null`, clear the site's cookies, log in again. |
| "Subscription required" page | The tenant has no active subscription (new tenant, or the term expired) | Activate or renew it in Platform → Subscriptions (Section K). |
| "No purchased seats are available" when adding or reactivating an employee | All purchased seats are used by active users | Deactivate an unused account, or request more seats from **Subscription & billing** and approve the request under Platform → Seat requests. |
| Tenant status `failed` | A provisioning step failed (often MySQL permissions) | Read the summary on the tenant details page and `storage/logs/laravel.log`, fix the cause, run `php artisan tenants:provision <subdomain>`. |
| Missing menu items, unexpected 403 on `/employees`, `/roles` or `/subscription` for a Tenant Admin of an older tenant | The tenant's roles/permissions predate the current code | `php artisan tenants:migrate`, then `php artisan tenants:sync-authorization`. |
| Login form keeps returning to the login page / "page expired" (419) | Stale session or cookies, the `sessions` table missing, or `APP_URL`/`SESSION_DOMAIN` misconfigured | Clear the browser cookies for that host, run `php artisan migrate`, keep `SESSION_DOMAIN=null`, then `php artisan config:clear`. Log in separately on the platform and on each tenant: sessions are not shared. |
| "Too many login attempts. Please try again in N seconds." | Login throttling after repeated failures (on the platform: 5 per email and IP) | Wait the number of seconds shown, then try again with the correct password. |
| Forgot a tenant user's password | — | Use "Forgot password" on the tenant login page; the reset link is written to `storage/logs/laravel.log` (`MAIL_MAILER=log`). |
| `could not find driver` or a missing-extension error | `pdo_mysql` or another PHP extension is disabled | Enable it in Laragon (*Menu → PHP → Extensions*) or `php.ini`. Check with `php -m` and `composer check-platform-reqs`. |
| Configuration changes in `.env` have no effect | Cached configuration | `php artisan config:clear`. |

The application log is `storage/logs/laravel.log`.

---

## O. Project Structure

| Path | Responsibility |
|---|---|
| `routes/web.php` | **Central routes**, bound to `CENTRAL_DOMAIN`: welcome page and everything under `/admin` |
| `routes/tenant.php` | **Tenant routes** (dashboard, employees, roles, subscription), loaded with the `web` + `tenant` middleware groups |
| `routes/settings.php` | Tenant account settings pages (included from `routes/tenant.php`) |
| `bootstrap/app.php` | The `tenant` middleware group, middleware priority, aliases, tenant route loading |
| `config/tenancy.php` | Tenancy configuration: central domain, tenant DB prefix, bootstrappers |
| `config/billing.php` | Currency allow-list, seat and price limits, renewal window |
| `config/fortify.php`, `config/auth.php` | Tenant authentication (`web`) and the Platform Admin guard (`platform`) |
| `database/migrations/` | **Central migrations** (`php artisan migrate`) |
| `database/migrations/tenant/` | **Tenant migrations** (run by provisioning and `php artisan tenants:migrate`) |
| `app/Models/Tenant.php` | Central tenant model: statuses, domains, subscription, seat limit, tenant URL |
| `app/Models/PlatformAdmin.php` | Central Platform Admin model |
| `app/Models/User.php`, `app/Models/Branch.php` | Tenant models (tenant database) |
| `app/Actions/Tenancy/` | Tenant provisioning (`ProvisionTenant`) and roles/permissions sync (`SyncTenantAuthorization`) |
| `app/Actions/Platform/` | Suspend, reactivate and edit tenants |
| `app/Actions/Employees/`, `app/Actions/Roles/` | Employee and role management, privilege-escalation rules |
| `app/Actions/Billing/`, `app/Billing/` | Plans, subscriptions, renewals, seat requests, charges, payments, money and proration |
| `app/Http/Controllers/Platform/` | Central platform controllers (login, dashboard, tenants, billing) |
| `app/Http/Middleware/` | `EnsureTenantIsReady`, `EnsureUserIsActive`, `EnsurePasswordIsChanged`, `EnsureTenantHasActiveSubscription` |
| `app/Policies/` | Authorization (tenants, users, roles, branches) |
| `app/Console/Commands/` | `platform:create-admin`, `tenants:provision`, `tenants:sync-authorization` |
| `app/Providers/TenancyServiceProvider.php` | Tenancy events, middleware priority, Livewire tenant routing |
| `resources/views/platform/` | Central platform pages (Blade + Flux) |
| `resources/views/pages/` | Tenant Livewire pages (employees, roles, subscription, settings) |
| `tests/` | Pest tests (`tests/Feature/Platform`, `Tenancy`, `Billing`); `tests/TestCase.php` holds the test-database safety guard |
| `docs/` | Milestone documentation |

---

## P. Important Notes

- **Never commit `.env`.** It is git-ignored. Keep `.env.example` free of real values.
- **Never share production credentials**, and never paste real passwords, temporary passwords or tokens into documentation, tickets or chat.
- **Check the target before any command that modifies databases.** Look at `DB_DATABASE` and the tenant prefix first. Avoid `migrate:fresh`, `db:wipe`, `tenants:migrate-fresh` and `tenants:rollback`.
- **Every tenant has an isolated database.** Tenant data exists only in `fcl_tenant_<uuid>`; there is no copy in the central database. Back up tenant databases individually.
- **Central and tenant authentication are separate.** Platform Admins log in at `/admin/login` on the central host; tenant users log in on their company's subdomain. Sessions are never shared.
- **Temporary passwords are shown once** (new tenants and new employees). If one is lost: for a tenant still being provisioned, `tenants:provision` generates a new one; for a user who can log in, use "Forgot password".
- `AGENTS.md` and `CLAUDE.md` contain conventions for AI coding assistants (`AGENTS.md` is generated locally by Laravel Boost and is not committed).

**Milestone documentation** (full details, decisions, tests and manual verification steps):

1. [Milestone 1 — SaaS Foundation](docs/MILESTONE_01_SAAS_FOUNDATION.md): multi-database tenancy, subdomains, provisioning, Platform Admin and tenant authentication.
2. [Milestone 2 — Tenant Users & Permissions](docs/MILESTONE_02_TENANT_USERS_PERMISSIONS.md): employees, roles, permissions, deactivation.
3. [Milestone 3 — Central SaaS Platform Management](docs/MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md): dashboard, tenant management, suspension, audit trail.
4. [Milestone 4 — Subscriptions, Seats & Billing](docs/MILESTONE_04_SUBSCRIPTIONS_BILLING.md): plans, annual subscriptions, seats, proration, renewals, payments.

---

## Q. Quick Start — Daily Development

For a machine where the first-time setup is already done:

1. **Laragon → Start All** (MySQL must be running).
2. **Terminal 1:**
   ```powershell
   php artisan serve
   ```
3. **Terminal 2:**
   ```powershell
   npm run dev
   ```
4. Open **`http://localhost:8000/admin/login`** (platform) or **`http://<subdomain>.localhost:8000/login`** (tenant).

After pulling new code, first run:

```powershell
composer install
npm ci
php artisan migrate
php artisan tenants:migrate
php artisan tenants:sync-authorization
```
