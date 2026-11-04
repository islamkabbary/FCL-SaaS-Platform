# Milestone 07: Username Login, Two-Factor Switch and Main Accounts

Date: 2026-11-04

This milestone did three things:

1. It switched off two-factor authentication for now, with one setting.
2. It lets people sign in with a username or an email. Every account gets a generated username, and tenant users can change theirs once.
3. It added a development seeder for three main accounts: `islam`, `zyad` and `ibrahim`.

## 1. Two-factor switch

Two-factor authentication only existed for Platform Admins. Tenant users never had it, because Fortify's two-factor feature is not enabled.

`PLATFORM_TWO_FACTOR_ENABLED` (`config('platform.two_factor_enabled')`, default `false`) is the master switch. `PlatformTwoFactor::isEnabled()` reads it. While it is off:

- Login goes straight in, even for an admin who already confirmed two-factor.
- `EnsurePlatformTwoFactor` asks for nothing, whatever `PLATFORM_REQUIRE_TWO_FACTOR` says.
- Starting a support session needs only the password, whatever `SUPPORT_REQUIRE_TWO_FACTOR` says.
- The challenge, security, QR and recovery code routes return 404 through `EnsureTwoFactorIsAvailable`.
- The account security link is hidden.

Nothing was deleted. Saved secrets and recovery codes stay, and setting the switch to `true` restores all of it. `phpunit.xml` sets it to `true`, so the existing security tests still cover the feature.

**This lowers security, and it is recorded as temporary in BD-SEC05.** It suspends the owner decision from Milestone 5.2 that made two-factor mandatory, and it puts that production blocker back in `docs/SAAS_MVP_READINESS.md`. A Platform Admin can open a support session inside any company, so these accounts are now protected by a password alone. Switch it on before production.

## 2. Usernames

### Format

A lowercase letter, then lowercase letters, digits, `.`, `-` or `_`, 3 to 30 characters (`App\Rules\ValidUsername`). It cannot contain `@`, so a login value can only match a username or an email, never both.

### Generation

`App\Concerns\HasUsername` generates a username on `creating`, so every path gets one: `CreateEmployee`, `ProvisionTenant`, `platform:create-admin` and the factories.

`App\Actions\Users\GenerateUsername` builds it from the name:

| Name | Username |
|---|---|
| `Islam Kabbary` | `islam.kabbary` |
| The second `Islam Kabbary` | `islam.kabbary2` |
| `زياد` | `zyd` |
| `***` or `Al` | `user` |

Usernames are unique per company for tenant users, and across the platform for Platform Admins.

Arabic names are converted letter by letter without vowels, so `إسلام` becomes `slm`. These usernames are valid but not always pretty, which is one reason the one-time change exists.

### One change

`App\Actions\Users\ChangeUsername`, from the profile page, applies to every tenant user, including Tenant Admins:

- It needs the current password, with the existing `CurrentPasswordConfirmation` lockout.
- It locks the row, so two simultaneous requests count as two changes.
- `username_changed_at` records the change. After that the field is read-only.
- It writes `account.username.changed` to the tenant audit log with the old and new values.
- The `changeUsername` policy allows only the owner. It is not on the impersonation allowlist, so support access and employee view are refused.

An admin cannot reset the change, and Platform Admin usernames cannot be changed from the interface.

### Login

- `config/fortify.php` now uses `login` as the username field. Password reset still uses `email`.
- The tenant and platform login pages have one "Username or email" field.
- The existing protections stay: timing checks, throttling and account lockout.
- Employees and provisioning screens show the username, and the employee list searches it.

## 3. Main accounts

`Database\Seeders\MainAccountsSeeder` creates:

- Platform Admins `islam`, `zyad` and `ibrahim`.
- The company `Demo Freight` on `demo.<central domain>`, with an active annual subscription and the same three people as Tenant Admins.

Rules:

- The password comes from `SEED_ACCOUNTS_PASSWORD` in `.env`, so it is not in Git.
- It refuses to run in production, and fails with a clear message if the password is missing.
- It can run again. It matches by username or email, updates the account and creates no duplicate.
- New accounts use `<username>@fcl.test`. These addresses receive no mail, so password reset does not reach them.

```bash
php artisan db:seed --class=MainAccountsSeeder
```

`DatabaseSeeder` now calls this seeder. Its previous `Test User` could not work, because the central database has no `users` table.

### What happened on the local development database

- The two new migrations and the pending locale migrations ran, in the central database and in the `test1` tenant.
- `islam` and `ibrahim` were created. **An existing Platform Admin named `zyad` (`zz@zz.com`) was reused: its email stayed, but its password changed.**
- `Demo Freight` was provisioned. The `zyad` user inside the `test1` company was not touched.

## 4. Files

| Area | Files |
|---|---|
| Two-factor | `config/platform.php`, `app/Auth/PlatformTwoFactor.php`, `app/Http/Middleware/EnsureTwoFactorIsAvailable.php`, `app/Http/Middleware/EnsurePlatformTwoFactor.php`, `app/Http/Controllers/Platform/AuthenticatedSessionController.php`, `app/Http/Controllers/Platform/SupportSessionController.php`, `app/Impersonation/Impersonator.php`, `routes/web.php`, `bootstrap/app.php`, `resources/views/layouts/platform.blade.php`, `phpunit.xml`, `.env.example` |
| Usernames | `app/Concerns/HasUsername.php`, `app/Actions/Users/*`, `app/Rules/ValidUsername.php`, `app/Enums/AccountAuditAction.php`, `app/Policies/UserPolicy.php`, `app/Models/User.php`, `app/Models/PlatformAdmin.php`, `resources/views/pages/settings/⚡profile.blade.php`, `resources/views/pages/employees/⚡index.blade.php`, `resources/views/platform/tenants/provisioned.blade.php`, `app/Http/Controllers/Platform/TenantController.php`, `app/Console/Commands/CreatePlatformAdmin.php` |
| Login | `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Http/Requests/Platform/LoginRequest.php`, `app/Http/Controllers/Platform/TwoFactorChallengeController.php`, both login views |
| Seeder | `database/seeders/MainAccountsSeeder.php`, `database/seeders/DatabaseSeeder.php` |
| Language | `lang/ar.json`, `lang/ar/validation.php`, `lang/en/validation.php` |

**Migrations:** `add_username_to_users_table` (tenant) and `add_username_to_platform_admins_table` (central). Each generates usernames for existing rows, then makes the column required and unique.

**Packages:** none added.

## 5. Tests

New files:

- `tests/Feature/UsernameLoginTest.php`: username and email login on both sides, capitals, wrong password, deactivated user, non-string input, throttling, isolation between companies, generation and the migration backfill.
- `tests/Feature/ChangeUsernameTest.php`: one change only, audit, wrong password, invalid or taken values, ownership, and refusal during a support session.
- `tests/Feature/PlatformTwoFactorSwitchTest.php`: login without a code, no enrollment, 404 pages, support session with the password only, and restoring two-factor.
- `tests/Feature/MainAccountsSeederTest.php`: accounts, demo company, repeat runs, reuse of an existing admin, and refusal in production or without a password.

Existing tests now post the `login` field. No test was removed.

Results:

- **Whole suite, one run:** 756 tests: 749 passed, 6 failed, 1 skipped, in about 18 minutes.
- **Five of the failures** came from the field rename. Four were test assertions on the old `email` error key. The fifth found a real bug: `TwoFactorChallengeController` sent its lockout and "log in again" messages to `email`, which the login page no longer shows. They now use `login`.
- **After those fixes,** only the affected files ran again: `PlatformTwoFactorTest`, `TenantIsolationTest`, `MainAccountsSeederTest`, `PlatformTwoFactorSwitchTest`, `UsernameLoginTest` and `ChangeUsernameTest`. All 75 tests passed. The whole suite was not repeated.
- **The sixth failure** is older than this milestone and remains: `tests/Feature/Tenancy/TenantAuditLogTest.php:231`. `getTableListing()` lists the tables of every MySQL database on the server, so the assertion finds `tenant_audit_logs` from tenant databases.

### Manual check

Chrome, on the development database, at 1280 pixels:

- `islam`, `ZYAD` and `ibrahim@fcl.test` signed in to `/admin`, and a wrong password stayed on the login page.
- `admin/account/security` returned Not Found.
- `zyad`, `ibrahim@fcl.test` and `islam` signed in to `demo.localhost`.
- The profile page shows the one-time form. The change itself was not used on the real accounts; the tests cover it.

## 6. Known issues

- **Weak protection for now:** password only, on accounts that can open any company. See BD-SEC05.
- **`.test` emails:** password reset does not reach the seeded accounts until they get real addresses.
- **Arabic names** produce usernames without vowels.
- **No reset of the one change.** A user who picks a wrong username needs a manual database fix.
- **Saved browser logins.** The field was renamed from `email` to `login`, so browser autofill may need one fresh login.

## 7. Deployment

```bash
php artisan migrate --force
php artisan tenants:migrate --force
npm run build
php artisan config:cache
```

Add `PLATFORM_TWO_FACTOR_ENABLED`. It defaults to off, so set it to `true` wherever two-factor must stay on. Do not run `MainAccountsSeeder` in production.

## 8. Manual verification

1. Open `/admin/login` and sign in as `islam`, then as `islam@fcl.test`. No code should be asked.
2. Open `/admin/account/security`. It should be Not Found.
3. Open `demo.localhost` and sign in as `zyad`.
4. Create an employee and confirm the dialog shows the generated username.
5. Sign in as that employee, change the username from the profile page, and confirm the form becomes read-only.
6. Sign out and confirm the new username works and the old one does not.
