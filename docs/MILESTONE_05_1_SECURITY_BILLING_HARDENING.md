# Milestone 5.1 — Security & Billing Hardening

This document describes what was **actually implemented and verified** in Milestone 5.1 (2026-10-20). The milestone fixes the defects found by the deep review of Milestones 1–5 ([review report](FCL_SaaS_MVP_Review_Report_2026-10-19.md)) before FCL Operations start. It builds on [Milestone 5](MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md); the overall assessment is in [SAAS_MVP_READINESS.md](SAAS_MVP_READINESS.md) §8.

> **Not a penetration test.** "Verified" means covered by automated Pest tests. Every fix has a permanent regression test, and each new test was run once against the code **without** its fix and confirmed to fail. No real-server or browser run was done in this milestone (§9).

---

## 1. Overview

| Area | Before | After |
|---|---|---|
| Tenant identification | any host ending with the central domain string resolved a tenant (`acme.evilfcl.app`) | only `{label}.{central domain}`; `trustHosts` outside `local` |
| Password change / reset | other sessions and remember-me cookies kept working | other sessions deleted, remember-me token rotated |
| Temporary-password restriction | not re-applied to Livewire updates | persistent Livewire middleware |
| `tenants:sync-authorization` | re-promoted a demoted founder on every run | legacy backfill only while nobody holds the role |
| Platform 2FA lockout | reset by signing in again with the password | checked before any code; lasts its window |
| End of impersonation | rotated the account's remember-me token | `logoutCurrentDevice()`; `/logout` handled in the middleware |
| Seats | only the current term counted | also capped by a confirmed later term |
| Seat increase after a reduction | reduction stayed scheduled | reduction superseded |
| Seat requests after an early renewal | compared with the wrong term, applied a year late | reductions wait for the new term; maximum checks both terms |
| Seat approval vs renewal | opposite lock order (deadlock possible) | same order |
| Confirmations | charged whatever the prices were at confirmation | refused unless the previewed amount still applies |
| Payment token reuse | returned another charge's payment as success | refused |
| Last Tenant Admin under concurrency | the lock did not work (snapshot read) | locking read through joins |
| Provisioning | no claim, stuck forever, creation audited last | atomic claim, stale takeover, audited first |
| Same subdomain at once | server error | validation error |
| Forgot password | revealed accounts, no IP limit | one neutral answer, 5 per minute per IP |
| `composer test` | stopped by Composer's 300 s timeout | runs to the end |

No migrations, no new packages, no configuration keys.

---

## 2. Commits (on `main`, not pushed)

| Hash | Commit |
|---|---|
| `ad7928a` | `fix(tenancy): identify tenants only from exact single-label subdomains` |
| `bc6f070` | `fix(auth): end other sessions when a tenant user's password changes` |
| `2539591` | `fix(permissions): stop tenants:sync-authorization from re-promoting founders` |
| `067d773` | `fix(security): keep the platform 2FA lockout across fresh password logins` |
| `221070a` | `fix(impersonation): keep the account's remember-me token when a session ends` |
| `aba63a5` | `chore(composer): let composer test run without the process timeout` |
| `65dd7eb` | `fix(billing): let an approved seat increase supersede a scheduled reduction` |
| `ba08ca9` | `fix(billing): cap new users at the seats of an already confirmed next term` |
| `9adfdef` | `fix(billing): handle seat requests correctly after an early renewal` |
| `aedde0d` | `fix(billing): lock the subscription first when approving a seat request` |
| `a3193af` | `fix(billing): only charge the amount the platform admin previewed` |
| `d7a3ce6` | `fix(billing): refuse a payment submission token reused for another charge` |
| `b8ed413` | `fix(employees): make the last-Tenant-Admin check safe under concurrency` |
| `fdc3077` | `fix(provisioning): claim tenants atomically and audit creation up front` |
| `19ea915` | `fix(platform): report a subdomain taken concurrently as a validation error` |
| `4708d33` | `fix(auth): stop the reset link form from revealing accounts and throttle it` |
| `acd49dc` | `docs(readiness): update the readiness assessment and earlier docs for 5.1` |
| (this document) | `docs(hardening): document Milestone 5.1 security and billing hardening` |

The full suite, PHPStan and Pint were run before every commit, so each commit was verified on its own.

---

## 3. Review findings and fixes

### 3.1 T1 — tenant resolved from a forged host (High)

- **Problem:** stancl's `InitializeTenancyBySubdomain::makeSubdomain()` uses `Str::endsWith($host, $centralDomains)` without a dot boundary and takes the first label. `acme.evillocalhost` and `acme.globex.localhost` resolved the tenant `acme`. In production, `POST /forgot-password` with `Host: acme.evilfcl.app` would send a genuine reset e-mail whose link points to the attacker's domain.
- **Fix:**
  - `app/Http/Middleware/InitializeTenancyByStrictSubdomain.php` extends the package middleware: the host must be exactly `{label}.{central domain}` with a valid label (`[a-z0-9]+(-[a-z0-9]+)*`); otherwise the existing 404 handler applies. Used in the `tenant` group and in the tenancy priority list.
  - `bootstrap/app.php`: `trustHosts` with the central domains and single-label subdomains of them (`subdomains: false`). Laravel applies it outside `local` and tests only; the strict middleware applies everywhere.
- **Deployment note:** `trustProxies` must list the load balancer's addresses, never `'*'` (readiness §4).
- **Tests:** `Tenancy/TenantIdentificationTest`: forged hosts return 404 (3 hosts); a forged-host reset request sends nothing; trusted host patterns (7 cases); middleware order uses the new class.

### 3.2 U4 — a password change did not end other sessions (Medium), with the temporary-password restriction

- **Problem:** changing (`⚡security`) or resetting (`ResetUserPassword`) a password left every other session and remember-me cookie working. Whoever signed in first with a temporary password stayed signed in. Because `must_change_password` belongs to the user, the owner's change also lifted that session's restriction. `EnsurePasswordIsChanged` was not re-applied to Livewire updates.
- **Fix:**
  - `app/Actions/Auth/EndOtherSessions.php`: rotates `remember_token` and deletes the user's rows in the tenant `sessions` table (database driver), except the given session.
  - Security settings page: called with the current session id. `ResetUserPassword`: called without one (the reset is done signed out).
  - `TenancyServiceProvider`: `EnsurePasswordIsChanged` added to Livewire's persistent middleware (a redirect becomes the update's response).
- **Behaviour:** the device that changed the password stays signed in; its own remember-me cookie stops working, so it signs in again when its session expires.
- **Tests:** `Settings/SecurityTest`, `Auth/PasswordResetTest`, `Tenancy/TemporaryPasswordTest` (real Livewire update request refused while the flag is set).

### 3.3 U1 — `tenants:sync-authorization` re-promoted a demoted founder (High)

- **Problem:** the legacy backfill gave the Tenant Admin role to every user with `is_tenant_admin = true` who lacked it. The flag is set at provisioning and never cleared, so a founder demoted by another admin became Tenant Admin again on the next deployment.
- **Fix:** `SyncTenantAuthorization` runs the backfill only while nobody in the tenant holds the Tenant Admin role, which is exactly the case of tenants provisioned before roles existed. `is_tenant_admin` keeps its meaning ("initial admin").
- **Tests:** `Tenancy/TenantAuthorizationIsolationTest`: a demoted founder stays demoted; the existing legacy backfill test still passes.

### 3.4 S1 — 2FA lockout bypassed by a fresh password login (Medium)

- **Problem:** `TwoFactorChallengeController::store()` verified the code first and checked the limiter only after a wrong code. With a stolen password, signing in again gave a fresh set of guesses, limited only by the per-IP route throttle.
- **Fix:** the per-account lockout (5 wrong codes, 300 seconds) is checked **before** any code is verified, like the support re-authentication. Wrong codes are counted from the value `RateLimiter::hit()` returns, and the message tells the admin when to try again.
- **Tests:** `Platform/PlatformTwoFactorTest`: a correct code after a fresh login is refused during the lockout and accepted after it.

### 3.5 S2 — ending an impersonation rotated the account's remember-me token (Low)

- **Problem:** `SessionGuard::logout()` rotates the remember-me token of the user being signed out. Starting a support session, ending one, and `/logout` during an employee view (through Fortify's logout) all used it, so the real Tenant Admin or employee lost "remember me" on all devices. Existing tests missed it because their accounts had no token.
- **Fix:**
  - `Impersonator::signOutCurrentDevice()` (`logoutCurrentDevice()`, invalidate, new CSRF token) for `beginSupport()` and `terminate()`.
  - `EnforceImpersonation` handles `/logout` during an impersonation itself: it ends the record, signs this browser out and returns Fortify's `LogoutResponse`. The request is no longer passed on. This also removes the dependency on middleware order noted by the review (a forged `/logout` could otherwise have removed the marker and left an unrestricted session).
- **Tests:** `Impersonation/SupportAccessTest` (end and logout), `Impersonation/EmployeeImpersonationTest` (logout during a view changes neither account).

### 3.6 B2 — an approved increase did not cancel a scheduled reduction (Medium)

- **Problem:** only a reduction superseded earlier reductions. After "2 seats → reduction to 1 approved → +3 approved and charged", the renewal proposed 1 seat.
- **Fix:** approving an increase marks any reduction still waiting for a renewal as `superseded`. The superseded request ids are stored in the approval's audit entry. The tenant can request a new reduction from the increased count.
- **Tests:** `Billing/SeatRequestTest`.

### 3.7 B1 — users added beyond the seats of a confirmed next term (High, revenue)

- **Problem:** the seat guard only read the current term. After an early renewal for fewer seats, a tenant could fill the current term's seats and enter the confirmed term with more active users than it pays for, for a whole year.
- **Fix:**
  - `Tenant::confirmedLaterTermSeats()`: the fewest seats of any term that starts after today.
  - `SeatUsage::available()` counts against the lower of the current and the confirmed later term; `isCappedByLaterTerm()`.
  - The purchased seat figure shown to the tenant stays the current term's. A specific error message and a notice on the tenant subscription page explain the cap.
- **Tests:** `Billing/SeatEnforcementTest` (the review scenario, the message, the notice, and the limit once the new term starts).

### 3.8 Seat requests after an early renewal (Low–Medium)

- **Problem:** requests were validated against the term covering today. After an early renewal, a reduction was compared with the old term's seats and would only have applied at the renewal after the confirmed term. The seat maximum ignored the confirmed term, which an approved increase also updates.
- **Fix:**
  - Reduction requests are refused while a later term is confirmed. Approving a reduction requested before the early renewal is refused too (the admin rejects it; the tenant asks again once the new term has started).
  - The seat maximum is checked against the larger of the current and the confirmed term, when requesting and when approving.
  - The tenant page replaces the reduction button with an explanation.
- **Tests:** `Billing/SeatRequestTest` (3 tests).

### 3.9 Lock order between seat approval and renewal (Low)

- **Problem:** approval locked the request and then the subscription; renewal locks the subscription and then updates requests. Run together, they could deadlock (server error).
- **Fix:** approval reads the request's subscription id, locks the subscription, then the pending request.
- **Tests:** `Billing/SeatRequestTest` records the order of the locking queries.

### 3.10 Confirmation bound to the preview (Medium)

- **Problem:** activation, renewal and seat-increase approval recomputed the charge at confirmation. A plan edited (or, for proration, a day passing) between preview and confirmation produced a charge nobody had reviewed, and charges cannot be edited.
- **Fix:** the three confirmation forms send the previewed total (`expected_total` / `expected_amount`), required by the controllers. `ActivateSubscription`, `RenewSubscription` and `ReviewSeatChangeRequest::approve()` compare it, under their locks, with the amount they are about to charge. If it differs, nothing is created and the new amount is shown. The parameter is optional in the actions for internal callers.
- **Tests:** `Billing/SubscriptionLifecycleTest`, `Billing/RenewalTest`, `Billing/SeatRequestTest` (approval on a later day than its preview). Existing HTTP tests now send the previewed totals, as the forms do.

### 3.11 Payment submission token reused on another charge (Low)

- **Fix:** `RecordPayment` treats a known token as a double submission only for the same charge; for another charge it refuses with a message to reload the page.
- **Tests:** `Billing/BillingPaymentTest`.

### 3.12 Last active Tenant Admin under concurrency (Medium)

The review reported that `UpdateEmployee` took its lock too late. Testing the fix showed the problem was wider: **none** of the three paths was protected, including `SetEmployeeActivation`, which looked correct.

- Spatie's `role()` scope first looks the role up with a plain read. Under MySQL's REPEATABLE READ, the first plain read fixes the transaction's snapshot, so this happened before the lock was even requested.
- The "last Tenant Admin" count was itself a plain read. After waiting for the lock, it still counted from the old snapshot and missed the other admin's committed change.
- A `FOR UPDATE` statement does not lock, or read the latest version of, rows read in a subquery (`whereHas`) (MySQL documentation).
- Self-deletion checked and deleted without a transaction or lock.

**Fix:**
- `User::activeTenantAdminCount()` counts through joins and, inside a transaction, as a locking read. It reads the latest committed rows and locks them until commit.
- `isLastActiveTenantAdmin()` uses it.
- Employee update, activation and self-deletion take it as the first statement of their transaction.
- Self-deletion checks and deletes in one transaction and logs out afterwards.

**Tests:** `Tenancy/EmployeeManagementTest`:
- **Proof with a second database session:** after the other session commits a demotion, a plain read inside the transaction still sees two admins; the locking count sees one. This is also the runtime evidence that the race was real.
- **Lock order:** the first query of the transaction is the lock (`firstQueriesOfTransactions()` helper in `tests/Pest.php`).

`SecurityHardeningTest` checks the same order for self-deletion.

### 3.13 Provisioning claim, stale runs and audit order (Medium)

- **Problem:** the status was set with a plain update, so two runs could migrate and seed the same tenant, and one admin would be shown a temporary password the other run had replaced. A run that died left the tenant `provisioning` forever. The creation was audited only after provisioning returned, so such a tenant had no audit entry.
- **Fix:**
  - `ProvisionTenant::claim()`: one atomic `UPDATE … WHERE status IN (pending, failed) OR (status = provisioning AND updated_at < now − 30 min)`. Otherwise `TenantProvisioningInProgress`, reported by `tenants:provision`.
  - `StaleAfterMinutes = 30`; `Tenant::isProvisioningStale()`; the tenant details page shows a stalled run and the command to resume it.
  - The `TenantCreated` audit entry is written in the same central transaction as the tenant record, before provisioning. Its `status` value is now `pending` (it was `ready` or `failed`).
- **Tests:** `Tenancy/TenantProvisioningTest` (concurrent run refused; stale run resumed), `Platform/TenantManagementTest` (audit written before the tenant database is created).

### 3.14 The same subdomain registered at once (Low)

- **Fix:** `TenantController::store()` turns `DomainOccupiedByOtherTenantException` (stancl's own check) and `UniqueConstraintViolationException` (the unique index, when both requests passed that check) into the usual "already taken" validation error. The central transaction is already rolled back.
- **Tests:** `Tenancy/TenantProvisioningTest` covers both moments of the race.

### 3.15 Forgot password: account disclosure and no IP limit (Low–Medium)

- **Fix:**
  - `app/Http/Responses/PasswordResetLinkRequestedResponse.php` is bound to Fortify's successful and failed reset link responses. A sent link, an unknown address and a broker-throttled request all get the same neutral message.
  - `app/Http/Middleware/ThrottlePasswordResetLinkRequests.php` (tenant group, only on `password.email`): 5 requests per minute per company and client IP.
- **Tests:** `Auth/PasswordResetTest`.

### 3.16 `composer test` timeout

The suite takes 4–6 minutes. `Composer\Config::disableProcessTimeout` is now the first step of the `test` script only; other scripts keep Composer's default.

---

## 4. Decisions taken in this milestone (owner may reverse)

| Decision | Reason | Alternative |
|---|---|---|
| An approved increase supersedes a scheduled reduction | the renewal must not default to fewer seats than were just paid for | keep the reduction and show a warning at renewal |
| No reduction requests while the next term is already confirmed | the request would only apply a year later and be compared with the wrong term | apply it at the renewal after the confirmed term |
| A "provisioning" tenant without progress for 30 minutes counts as abandoned | normal provisioning takes seconds | a different threshold, or only a manual reset |
| The TenantCreated audit records `status: pending` | it is written before provisioning | add a separate "provisioned" audit entry |
| The device that changed its password keeps its session; its remember-me cookie stops working | simplest safe behaviour | re-issue the cookie for the current device |
| Forgot password: 5 requests per minute per company and IP | blocks probing and mail flooding, invisible to normal users | another limit |

---

## 5. Migrations, packages and configuration

- **Migrations:** none.
- **Packages:** none added or updated. `composer audit`: no advisories; `npm audit`: 0 vulnerabilities (2026-10-20).
- **Configuration:** `composer.json` (the `test` script). No new `.env` or `config/` keys.
- **Deployment:** nothing new to run. `tenants:sync-authorization` remains a deployment step; it no longer re-promotes demoted founders.

---

## 6. New and modified files

### New
- `app/Actions/Auth/EndOtherSessions.php`
- `app/Exceptions/TenantProvisioningInProgress.php`
- `app/Http/Middleware/InitializeTenancyByStrictSubdomain.php`
- `app/Http/Middleware/ThrottlePasswordResetLinkRequests.php`
- `app/Http/Responses/PasswordResetLinkRequestedResponse.php`
- `docs/MILESTONE_05_1_SECURITY_BILLING_HARDENING.md`

### Modified (application)
- **Billing actions** (`app/Actions/Billing/`): `ActivateSubscription.php`, `RenewSubscription.php`, `RequestSeatChange.php`, `ReviewSeatChangeRequest.php`, `RecordPayment.php`
- **Other actions:**
  - `app/Actions/Employees/`: `UpdateEmployee.php`, `SetEmployeeActivation.php`
  - `app/Actions/Fortify/ResetUserPassword.php`
  - `app/Actions/Tenancy/`: `ProvisionTenant.php`, `SyncTenantAuthorization.php`
- **Billing:** `app/Billing/SeatGuard.php`, `app/Billing/SeatUsage.php`, `app/Exceptions/NoSeatAvailable.php`
- **Commands:** `app/Console/Commands/ProvisionTenantCommand.php`
- **Platform controllers** (`app/Http/Controllers/Platform/`): `SeatRequestController.php`, `SubscriptionController.php`, `TenantController.php`, `TwoFactorChallengeController.php`
- **Impersonation:** `app/Http/Middleware/EnforceImpersonation.php`, `app/Impersonation/Impersonator.php`
- **Models:** `app/Models/Tenant.php`, `app/Models/User.php`
- **Providers:** `app/Providers/FortifyServiceProvider.php`, `app/Providers/TenancyServiceProvider.php`
- **Bootstrap and Composer:** `bootstrap/app.php`, `composer.json`
- **Tenant pages** (`resources/views/pages/`): `settings/⚡security.blade.php`, `settings/⚡delete-user-modal.blade.php`, `subscription/⚡index.blade.php`
- **Platform pages** (`resources/views/platform/`): `seat-requests/index.blade.php`, `subscriptions/activate.blade.php`, `subscriptions/renew.blade.php`, `tenants/show.blade.php`

### Modified (tests)
- **Helpers:** `tests/Pest.php` (`firstQueriesOfTransactions()`)
- **Auth and settings:** `Auth/PasswordResetTest`, `Settings/SecurityTest`, `SecurityHardeningTest`
- **Billing:** `BillingPaymentTest`, `RenewalTest`, `SeatEnforcementTest`, `SeatRequestTest`, `SubscriptionLifecycleTest`
- **Impersonation:** `EmployeeImpersonationTest`, `SupportAccessTest`
- **Platform:** `PlatformTwoFactorTest`, `TenantManagementTest`
- **Tenancy:** `EmployeeManagementTest`, `TemporaryPasswordTest`, `TenantAuthorizationIsolationTest`, `TenantIdentificationTest`, `TenantProvisioningTest`

### Removed
- `tests/Feature/Review/ReviewFindingsTest.php`: the eight opt-in review proofs were moved, rewritten where needed, into the files above as regular tests.

### Documentation updated
- `docs/SAAS_MVP_READINESS.md`
- `docs/MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md`
- `docs/FCL_SaaS_MVP_Review_Report_2026-10-19.md`
- `docs/FCL_SaaS_Complete_Project_Handover_2026-10-19.md`

---

## 7. Tests and results

| Check | Result (2026-10-20, Windows, PHP 8.4.21, MySQL 8.4.3, `partial_revokes=0`) |
|---|---|
| Baseline before 5.1 | 387 passed, 9 skipped (the tenant 2FA starter test and the 8 opt-in proofs); with `RUN_REVIEW_PROOFS=1` the 8 proofs failed, as reported by the review |
| Pest after 5.1 | **427 tests: 426 passed, 1 skipped** (tenant 2FA starter test, feature disabled), 1,882 assertions |
| `composer test` | runs to the end: Pint, PHPStan, Pest |
| PHPStan (level 7) | 0 errors |
| Pint | passed |
| `npm run build` | passed |
| `npm audit` / `composer audit` | 0 / no advisories |
| Fix verification | every new or changed test was run once with the fix removed and failed |

- **New permanent tests:** 39 (427 − 388), including the eight former proofs.
- **Databases used:** `fcl_central_testing` and `fcl_test_tenant_*` only, through `fcl_app`, which is limited to `fcl\_%`. The development database `fcl_central` was only read.
- **Not done:** no real-server run and no browser run (the development database has no tenant, and none was created without the owner's approval). True parallel-process races were not run. The concurrency fixes are verified through deterministic tests: a second database session, and the order of locking queries.

---

## 8. Known limitations and open review notes

| Item | Severity | Note |
|---|---|---|
| `RolePolicy::update/delete` ignore who holds a role | Low | editing a shared role can reduce the rights of a user above the actor |
| Support hand-over not bound to the starting IP | Low | `handoff_ip` is recorded, not compared; the credential is single-use, short-lived and tenant-bound |
| Livewire `__dispatch` and `updating*` hooks run before the impersonation listener | Low | no current exposure; the first `#[On]` handler on a page outside the allowlist must be reviewed |
| `regenerateRecoveryCodes` accepts a code from an unconfirmed secret | Low | review with the 2FA enrollment flow |
| Profile email not lowercased | Low | safe today because of the `_ci` collation |
| Employee role with a permission the creator lacks | Low | creating employees then fails without a clear message |
| Audit records append-only at the model level only | Low | `query()->update()` and `DB::table` bypass the model guards; tamper-evidence is a production topic |
| Stale-provisioning threshold | Info | 30 minutes, fixed in code (`ProvisionTenant::StaleAfterMinutes`) |
| Production blockers | — | unchanged except the resolved password policy: readiness §5.1 |

---

## 9. Manual verification steps (owner, in the browser)

Use a local tenant such as `acme` (`http://acme.localhost:8000`) with a subscription, a Tenant Admin and one employee.

1. **Forged host (T1).** In a terminal: `curl -i -H "Host: acme.evillocalhost" http://127.0.0.1:8000/login` returns **404**; with `Host: acme.localhost` it returns **200**.
2. **Password change ends other sessions (U4).** Sign in as the employee in Chrome and in a private window. Change the password in Chrome (Settings → Password). Reload the private window: it is back on the login page. Chrome stays signed in.
3. **Temporary password.** Create an employee and sign in with the temporary password in a private window, but do not change it. Sign in as the same employee in Chrome and change the password there. The private window must not be able to use the application.
4. **Forgot password.** On `/forgot-password`, an unknown and a known address show the same message. A sixth request within a minute shows "Too many password reset requests".
5. **Platform 2FA lockout (S1).** With 2FA enabled for a Platform Admin, enter five wrong codes, then sign in again with the password and enter a correct code: it is refused with "try again in … seconds". After 5 minutes it works.
6. **Support session end (S2).** Sign in as the Tenant Admin with "Remember me" in one browser. From the central platform start a support session for `acme` in another browser and end it. In the Tenant Admin's browser, delete only the session cookie (DevTools → Application → Cookies, the `…_session` cookie) and reload: the admin is still signed in through "Remember me". Before 5.1 this cookie stopped working.
7. **Founder demotion (U1).** With two Tenant Admins, demote the founder to Employee. Run `php artisan tenants:sync-authorization`: the founder stays an Employee.
8. **Seat increase after a reduction (B2).** Request a reduction and approve it; request an increase and approve it. On the subscription page the reduction shows "Superseded", and the renewal preview proposes the increased seat count.
9. **Early renewal (B1, §3.8).** Approve a reduction and renew early with the reduced seats. On the tenant subscription page, a notice explains the cap; adding employees above the renewed seat count is refused; the reduction button is replaced by an explanation.
10. **Preview binding.** Open a renewal preview, change the plan's prices in another tab, then confirm the first preview: it is refused and shows the new total; nothing is charged.
11. **Provisioning.** Create a tenant: its history shows "created" with status `pending`. Running `php artisan tenants:provision <subdomain>` for a ready tenant reports "already provisioned".
12. **Last Tenant Admin.** With one Tenant Admin, try to deactivate, demote or delete that admin: each is refused.

---

## 10. Final state

- Branch `main`; 18 commits after `524ec96`, author and committer `Islam Kabbary <islamkabbary@gmail.com>`, no AI attribution. **Not pushed.**
- Working tree clean after the documentation commit.
- Next steps: the owner's browser verification (§9 and the M5 doc §18), the open production decisions in [SAAS_MVP_READINESS.md](SAAS_MVP_READINESS.md) §5.1, then the scope of the first FCL Operations milestone.
