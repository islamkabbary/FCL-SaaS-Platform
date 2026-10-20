# Milestone 5 — Support Access, Restricted Impersonation & SaaS Security Finalization

This document describes what was **actually implemented and verified** in Milestone 5. It builds on Milestones [1](MILESTONE_01_SAAS_FOUNDATION.md), [2](MILESTONE_02_TENANT_USERS_PERMISSIONS.md), [3](MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md) and [4](MILESTONE_04_SUBSCRIPTIONS_BILLING.md). The overall readiness assessment of the SaaS management MVP is in [SAAS_MVP_READINESS.md](SAAS_MVP_READINESS.md).

> **Important:** this milestone did **not** include a professional penetration test. "Verified" below means covered by automated Pest tests and/or the real-server checks in §17, not audited by a third party.

> **Production blocker found before this milestone:** an uncommitted local change outside this milestone lowers the Platform Admin password minimum from 12 to 6 characters (§11.1). It is not part of any Milestone 5 commit and still needs an owner decision.
>
> **Updated by [Milestone 5.1](MILESTONE_05_1_SECURITY_BILLING_HARDENING.md) (2026-10-20):** the password-minimum change no longer exists (the code and working tree have `min(12)`). Ending an impersonation or starting a support session now signs the identity out with `logoutCurrentDevice()`, which leaves the account's remember-me token unchanged, and `/logout` during an impersonation is handled by `EnforceImpersonation` itself. The tenant is identified by `InitializeTenancyByStrictSubdomain`. The Platform Admin 2FA lockout now also applies after a fresh password login. The sections below are corrected where these changes apply.

---

## 1. Milestone Overview

**Goal:** temporary, auditable, restricted support access for Platform Admins and Tenant Admins, followed by a security review of the SaaS management MVP.

| Requirement | Status | Evidence |
|---|---|---|
| Platform Admin → Tenant Admin support session | Implemented, verified | `SupportAccessTest` (36), real server §17 |
| Tenant Admin → Employee view | Implemented, verified | `EmployeeImpersonationTest` (20), real server |
| Restricted interactive policy (HTTP, Livewire, Gate) | Implemented, verified | `RestrictionPolicyTest` (32), real server |
| Session lifecycle: expiry, end, revocation, termination conditions | Implemented, verified | all three test files |
| Audit with original actor attribution | Implemented, verified | tests + real server |
| In-app tenant notification of support access | Implemented, verified | tests + real server |
| Platform Admin 2FA (TOTP, recovery codes, CLI reset) | Implemented, verified | `PlatformTwoFactorTest` (12), real server |
| Livewire upload routes secured | Disabled (404), verified | `SecurityHardeningTest`, real server |
| Security review and readiness report | Done | §12–13, [SAAS_MVP_READINESS.md](SAAS_MVP_READINESS.md) |
| Browser testing | **Not performed** | no browser tool in this environment; steps in §18 |

**Support access is disabled by default** (`SUPPORT_ACCESS_ENABLED=false`). It must be enabled explicitly per environment.

---

## 2. Implemented Features

**Central platform**
- **Start support session** button on Tenant Details (only when enabled and the tenant is ready and not suspended).
- Start page: company, subdomain, the tenant's **active Tenant Admins** to choose from, mandatory reason, the admin's own password and, by default, a fresh authenticator code.
- An automatic, credential-carrying hand-over to the tenant subdomain (§4).
- **Support sessions** page (navigation "Support"): history with status and filter, details per session (activity trail), **Revoke**. The tenant page lists its 5 latest sessions.
- **Account security** page (click your email in the header): set up, confirm or disable an authenticator app and regenerate recovery codes.
- Two-step Platform Admin login when 2FA is enabled. "Remember me" was removed from the platform login.

**Tenant**
- A persistent **impersonation banner** on every page showing the initiator type and identity, the target, the end time with a live countdown, and **End impersonation**.
- **Employees → View as** (Tenant Admins only): reason and own password, then a restricted view as the employee.
- **Access history** (Tenant Admins only): platform support sessions in this company (who, why, when, status) with **Revoke**; employee views with **End**; "mark as read" for support notices. The dashboard also shows a notice when there is unread support access.

**Security fixes and hardening** (§13): Livewire temporary upload and local-file serving routes disabled; baseline security headers; cross-identity authorization now denies instead of throwing; Gate ordering fixed for impersonation; seat requests authorized through the Gate; the last active Tenant Admin can no longer delete their own account.

---

## 3. Support Impersonation Architecture

```
Central (localhost)                                     Tenant (acme.localhost)
──────────────────                                      ───────────────────────
Tenant Details → Start support session
  GET  /admin/tenants/{id}/support      eligibility + active Tenant Admins (read from the tenant DB)
  POST /admin/tenants/{id}/support      password (+ TOTP) re-checked, reason, target
     StartSupportSession:
       support_sessions row (pending), audit "support.session_started",
       notification to every active Tenant Admin (tenant DB)
     ← HTML page with an auto-submitting POST form (no-store, no-referrer)
                                     ───────────────────────────────▶ POST /support/handoff  {token}
                                                                       ConsumeSupportHandoff:
                                                                         atomic claim, eligibility re-check,
                                                                         sign out this device + invalidate, login target,
                                                                         regenerate session, set marker
                                                                       → /dashboard (restricted, banner)
Every tenant request: EnforceImpersonation re-validates the marker against support_sessions.
End impersonation / logout / expiry / revocation / termination conditions → tenant session logged out.
```

**Classes**
- `App\Actions\Impersonation\StartSupportSession`: eligibility, targets, creation, audit, notification.
- `App\Actions\Impersonation\ConsumeSupportHandoff`: the tenant side of the hand-over.
- `App\Impersonation\Impersonator`: switches identities, decides on every request whether a session may continue, tears it down and audits its end.
- `App\Http\Middleware\EnforceImpersonation`: the per-request check and the HTTP restriction layer.
- `App\Impersonation\RestrictionPolicy`: the allowlist (§6).
- `App\Providers\ImpersonationServiceProvider`: Livewire and Gate restriction layers, abilities.

**Eligibility** (checked when starting, and again at the hand-over):
- the tenant is **ready** (not pending, provisioning or failed);
- the tenant is **not suspended** (suspension is never bypassed);
- its subscription **allows access** (Active or Legacy, never None or Expired);
- the target is an **active Tenant Admin** of that tenant, chosen from the list read from its database, with an unchanged email at the hand-over;
- the Platform Admin still exists and still holds `start-support-session`, which includes the feature flag.

**Per-tenant authorization:** a session and its credential are bound to one tenant id, and tenant sessions are scoped per tenant (`ScopeSessions`). Access to another company always needs a new session with its own reason and re-authentication.

---

## 4. Cross-Domain Authentication Design

### 4.1 Why not stancl/tenancy's `UserImpersonation` feature
The package feature (`Stancl\Tenancy\Features\UserImpersonation`, v3.10.1) was reviewed and **not enabled**:

| Concern | Package behavior | This implementation |
|---|---|---|
| Credential storage | 128 random characters stored **in plaintext** as the primary key | only the **SHA-256** hash is stored, cleared on use |
| Transport | designed for a **URL** (`/impersonate/{token}`): browser history, access logs, Referer | **POST body** of an auto-submitted form; response `no-store`, `Referrer-Policy: no-referrer` |
| Single use | `findOrFail` → check → login → `delete()`: two concurrent requests can both log in | one conditional `UPDATE … WHERE hash = ? AND tenant = ? AND not consumed AND not ended AND not expired`; only one request can succeed |
| Binding | tenant + user only | tenant, target user (id and email), initiating Platform Admin, a recorded reason and purpose |
| Session fixation | logs in into the existing session | logs out, **invalidates** the session, logs in, **regenerates** the id and CSRF token |
| Restrictions, expiry, audit | none (a normal, unlimited login) | full restricted policy, lifetime, audit, revocation |
| Redirect | stores an arbitrary `redirect_url` | fixed redirect to `/dashboard`; no redirect parameter accepted |

The same building blocks are used (central record, short-lived credential, tenant-side consumption), hardened as above.

### 4.2 The hand-over credential
- **Generation:** `bin2hex(random_bytes(32))`, 256 bits from the CSPRNG (64 hex characters).
- **Validity:** at most **60 seconds** (`SUPPORT_HANDOFF_SECONDS`, clamped to 10–60), and inside the session lifetime.
- **Single use:** consumed by one atomic conditional `UPDATE`, which also clears the stored hash. Replays and concurrent duplicates get **403**. Verified with 6 parallel requests on the real server: 1 accepted, 5 refused.
- **Tenant binding:** the claim requires `tenant_id` to equal the identified tenant. A credential **presented to another tenant is burned** (its session ends with `session_mismatch`), so it cannot be used on the right tenant afterwards either.
- **Never logged:** not in the audit trail, the events, notifications, URLs or application logs (verified by searching the stored data, §17).
- **CSRF:** `/support/handoff` is the only route exempt from CSRF verification, because the POST comes from the central domain. The unguessable single-use credential takes the place of the CSRF token. All other tenant routes keep CSRF protection (real server: login without a token → 419).
- **Open redirects:** none. The hand-over always redirects to the tenant dashboard, and ending a support session redirects to a fixed central route.

### 4.3 Compatibility with the existing architecture
- **Guards:** the Platform Admin's `platform` guard session on the central host is never read or written by the tenant. The tenant session logs in through the tenant `web` guard as the Tenant Admin. Cookies are host-only (`SESSION_DOMAIN=null`), so the two sessions never share a cookie. Real server: the central session stays valid during and after the support session.
- **Databases:** the support record is central (`CentralConnection`). The target user and notifications are read and written in the tenant database.
- **Session handler:** `TenantAwareDatabaseSessionHandler` keeps the tenant session in the tenant database, as for any tenant login.
- **Livewire:** the update route runs the `tenant` group, so `EnforceImpersonation` covers every update.
- **Middleware order:** `InitializeTenancyByStrictSubdomain (InitializeTenancyBySubdomain before M5.1) → PreventAccessFromCentralDomains → EnsureTenantIsReady → … StartSession → ScopeSessions → EnforceImpersonation → EnsureUserIsActive → auth → …`. It is enforced through the middleware priority list, so impersonation is verified before anything uses the authenticated user.

---

## 5. Tenant Admin Login As Employee

- **Who:** only an **active user holding the Tenant Admin role** (ability `impersonate`, `EmployeeImpersonationRules`).
- **Whom:** an **active** user of the **same tenant** (users are only ever looked up in the current tenant database). Refused targets:
  - **oneself**;
  - another **Tenant Admin**;
  - anyone with **equal or higher privileges**: the target holds every permission the initiator holds, or more.
- **How:**
  1. **Employees → View as** opens a dialog asking for a reason (5–500 characters) and the Tenant Admin's **own password**. The password is checked by the backend action and rate limited: 5 attempts, keyed per tenant and admin.
  2. The admin's session switches to the employee's identity, with a new session id and CSRF token.
  3. The employee's password, remember token and sessions are never read or changed (tested). **Correction (M5.1):** until Milestone 5.1, logging out during an employee view went through Fortify's logout, which rotated the employee's remember-me token. Since M5.1 the view is ended with `logoutCurrentDevice()` and neither account is changed (tested).
- **End:** "End impersonation" restores the Tenant Admin's own identity, again with a new session id. If the initiator is no longer an active Tenant Admin, the browser is logged out instead of being restored.
- **Nested and circular impersonation** are refused at three levels:
  - the start action refuses while a session marker exists;
  - the Livewire layer refuses the methods;
  - the Gate refuses the `impersonate` ability for an impersonated user.
- **Permanent records:** the view creates no role, permission or user records. Only the tenant-local audit record (§9) is written.
- **Lifetime:** `EMPLOYEE_IMPERSONATION_LIFETIME`, default **15 minutes**, clamped to 1–60. The feature can be switched off with `EMPLOYEE_IMPERSONATION_ENABLED=false`, which also ends running views.

---

## 6. Restricted Interactive Permission Policy

The policy is an **allowlist** (`App\Impersonation\RestrictionPolicy`). Anything not listed is refused, so later features are **denied by default** while impersonating, including FCL Operations and financial functions, until they are reviewed and added.

| Layer | Where | Allowed while impersonating |
|---|---|---|
| HTTP | `EnforceImpersonation` (every tenant request, including Livewire and Fortify routes) | all `GET`/`HEAD` requests; non-GET only for `impersonation.end`, `logout`, `livewire.update` |
| Livewire methods | `Livewire::listen('call')` | `$refresh`, `$set`, `$sync`, `$commit`, `gotoPage`, `previousPage`, `nextPage`, `setPage`, `resetPage` |
| Livewire property updates | `Livewire::listen('update')` | only components reviewed as side-effect free when properties change: employees, roles, subscription and the settings pages (search boxes, filters, unsaved form fields) |
| Authorization | `Gate::before` (registered **before** spatie/laravel-permission's check) | abilities `viewAny`, `view`, `employees.view`, `roles.view`, `billing.view` |

Every refusal at the HTTP and Livewire layers returns **403** and is recorded with the route name or `component@method`, never request data. Viewing pages is allowed, and page views are recorded (§9).

**Refused, verified by tests:**
- passwords, email and name changes, account deletion;
- confirming the target's password;
- creating, editing, activating or deactivating employees;
- creating, editing or deleting roles, and assigning roles;
- seat increases, seat reductions and cancelling seat requests;
- starting another impersonation;
- the support access history;
- any central platform or billing endpoint, which returns 404 on tenant hosts.

The backend actions themselves (`CreateEmployee`, `SetEmployeeActivation`, `SyncEmployeeRoles`, `SaveRole`, `RequestSeatChange`) refuse the impersonated user even when called directly. A component that nobody reviewed (simulated with a probe component) refuses property updates and methods.

**Not bypassable through:** hidden UI (the backend refuses), a still-open Livewire page (checked on every update), tenant suspension, subscription rules (the normal `subscription.active` and suspension middleware still apply, and impersonation ends on suspension or subscription loss), or the forced password change. Impersonated sessions skip the forced password change (the password belongs to the target), and changing a password is refused anyway.

**Audit attribution guard:** `PlatformAuditLog::recordForTenantUser` refuses to write an entry for the impersonated user. A recorded action can never look as if the target performed it.

---

## 7. Session Lifecycle

| State (derived, never stored) | Support session | Employee view |
|---|---|---|
| Pending | created, hand-over not used yet | — |
| Active | hand-over used, not ended, before `expires_at` | not ended, before `expires_at` |
| Expired | time limit reached, or hand-over unused after 60 s | time limit reached |
| Ended | ended by the initiator, logout, or a termination condition | same |
| Revoked | revoked by a Platform Admin or a Tenant Admin | ended by another Tenant Admin |

Each record has a source actor, target identity, tenant, immutable `started_at` and `expires_at`, a reason, and `ended_at` / `end_reason` / `ended_by_email`. Identity, reason and timestamps cannot be changed through the model (`IsImpersonationSession`), records cannot be deleted, and a session ends **at most once** (conditional `UPDATE … WHERE ended_at IS NULL`).

Support lifetime: `SUPPORT_SESSION_LIFETIME`, default **15 minutes**, clamped to 1–60, counted from creation.

---

## 8. Session Expiration and Revocation

`EnforceImpersonation` runs on **every tenant request**. It re-reads the record and ends the impersonation when any of these holds:

| Condition | End reason |
|---|---|
| marker does not match the record, tenant, target or initiator | `session_mismatch` |
| time limit reached | `expired` |
| ended or revoked elsewhere (central "Revoke", tenant "Revoke"/"End") | existing reason (`revoked`, …) |
| feature disabled | `feature_disabled` |
| initiator lost authorization (Platform Admin deleted or not allowed; Tenant Admin deactivated or no longer Tenant Admin) | `actor_unauthorized` |
| tenant suspended since the session started (even if reactivated since) | `tenant_suspended` |
| subscription no longer allows access | `subscription_inactive` |
| target deactivated | `target_deactivated` |
| target no longer eligible (lost Tenant Admin role; employee now outranks the initiator) | `target_ineligible` |

Suspension and deactivation also **end the records immediately** (`EndImpersonationsFor`), so the history shows the real cause even if no further request comes.

**Teardown:**
- A support session is logged out of the tenant and the session is invalidated.
- An employee view returns to the initiating Tenant Admin, with a regenerated session, if they are still eligible; otherwise it is logged out.
- The request that discovered the end **never continues** under either identity: page requests are redirected, and Livewire or JSON requests get 403.
- Impersonation state is kept on the request attributes, never in a container singleton or static, so it cannot leak between requests of a long-running process (tested in the same PHP process).

**Revocation:** central **Support sessions → Revoke**, or tenant **Access history → Revoke / End**. The impersonated browser loses access on its next request (tested, and on the real server).

---

## 9. Audit Records and Actor Attribution

**Central (`platform_audit_logs`, read-only through the application):**

| Action | Actor |
|---|---|
| `support.session_started` (reason, target, expiry) | the Platform Admin |
| `support.session_ended` (end reason) | the Platform Admin when they ended it; `system` for expiry, suspension and the other conditions |
| `support.session_revoked` | the revoking Platform Admin, or the Tenant Admin (`tenant_user`) |
| `admin.two_factor_enabled` / `_disabled` / `recovery_codes_regenerated` | the Platform Admin |
| `admin.two_factor_reset` | `system` (console) |

A new actor type **`system`** (`PlatformAuditLog::recordSystem`) records actions taken by the application or the console, shown as "Automatic" in the history. Nothing is attributed to a person who did not do it.

**Session records and activity trails:**
- `support_sessions` (central): who, as whom, where, why, the timestamps and the end reason. Start and hand-over IP addresses are kept, never session ids or credentials.
- `support_session_events` (central) and `impersonation_events` (tenant): every **page viewed** (route name) and every **refused operation** (route, or `component@method`, or `component#property`), with the HTTP method. Request data is never stored.
- `impersonation_sessions` (**tenant-local**): employee views with the original Tenant Admin, target, reason, timestamps and end reason. No employee-view data is written to the central database.

Event records are append-only. Session records only change once, when they end.

---

## 10. Tenant Notifications

- When a support session starts, every **active Tenant Admin** of the company receives an **in-app database notification** (`SupportAccessStarted`, Laravel's `notifications` table in the tenant database). It holds the support staff name and email, reason, target account, start time and expiry.
- The current status is always read live from the central support record.
- The **Access history** page lists the company's support sessions (this tenant's only) and lets a Tenant Admin revoke an active one. The dashboard shows a notice while there are unread notifications.
- No email or external service is used or required.

---

## 11. Authentication Hardening

### 11.1 Pre-existing uncommitted change (owner decision required)

> **Resolved (2026-10-20):** this change no longer exists. The working tree is clean and `platform:create-admin` requires `Password::min(12)`. The text below records the state at the end of Milestone 5.

`git diff app/Console/Commands/CreatePlatformAdmin.php`:

```diff
-                'password' => ['required', 'confirmed', Password::min(12)],
+                'password' => ['required', 'confirmed', Password::min(6)],
```

- **The minimum is weakened.** `platform:create-admin` is the only way to create Platform Admins. With the change, a 6-character password is accepted for the most privileged accounts.
- **Impact:**
  - online guessing is rate limited (5 attempts per email and IP per minute);
  - 2FA, now available, mitigates it when enabled;
  - offline cracking of a leaked bcrypt hash of a 6-character password is fast.
- **Not production-ready.** The change was not made in this milestone. It is left untouched, uncommitted and outside every Milestone 5 commit.
- **Owner decision:** restore `Password::min(12)`, and consider the production defaults in `AppServiceProvider`: mixed case, numbers, symbols and an uncompromised check. Or explicitly confirm the weaker policy, which this review does not recommend.
- Existing tests still pass with it, because they only check a 5-character password. A test for an 11-character password was **not** added, because it would fail against the owner's working copy.

### 11.2 Platform Admin 2FA (implemented)
Fortify's 2FA is bound to the tenant `web` guard and its routes run in tenant context, so it cannot serve the central `platform` guard without breaking tenant authentication. The central guard therefore uses the same building blocks directly (`App\Auth\PlatformTwoFactor`: google2fa, Fortify's recovery code format, the bacon QR renderer). **Tenant authentication is unchanged.**

- **Opt-in per admin.** Enrolling and confirming require the admin's password and a code.
- **Storage:** the secret and recovery codes are **encrypted at rest** (`encrypted` casts, keyed by `APP_KEY`) and hidden from serialization.
- **Login:**
  1. The password step creates a pending login (5 minutes) without authenticating.
  2. The challenge accepts a TOTP code (±1 step window) or a recovery code.
  3. After 5 wrong codes the pending login is discarded.
- **Replay protection is per admin and durable.** The accepted time step is stored with a compare-and-set, so a code works once even across two simultaneous requests. Fortify's default cache key, by contrast, is global per code value.
- **Recovery codes:**
  - 8 codes, each usable once;
  - shown once in a `no-store` response, never flashed to the session;
  - can be regenerated with the password and a code.
- **Disabling** needs the password and a current or recovery code. Every change is audited.
- **Recovery procedure (lost device and codes):** an operator with server access runs `php artisan platform:reset-two-factor <email>` (asks for confirmation; `--force` skips it). The reset is audited as a `system` action. The admin then logs in with their password and enrolls again.
- **Support sessions require 2FA by default** (`SUPPORT_REQUIRE_TWO_FACTOR=true`). An admin without confirmed 2FA cannot start one, and every start needs a **fresh code** (a code already used for login is refused).

**Not enforced for logins:** Platform Admins *can* still log in without 2FA if they have not enrolled. Making it mandatory for every Platform Admin login is a policy decision; see the readiness report.

### 11.3 Other platform authentication findings
| Item | Finding | Action |
|---|---|---|
| Password policy | see §11.1; production defaults exist in `Password::defaults()` but `platform:create-admin` uses its own rule | owner decision |
| Login rate limiting | 5 per email+IP, central `LoginRequest`; 2FA challenge 5 per admin plus route throttle 10/min; support start 5 per admin; handoff 20/min per IP | kept / added |
| Password confirmation | fresh password (and TOTP) **in the same request** for support sessions and 2FA changes; no reusable "confirmed recently" window | added |
| Session regeneration | on login and on the 2FA step; logout invalidates | verified |
| Remember me | removed for Platform Admins (no long-lived cookie for the privileged guard) | changed, tested |
| Guard separation | `platform` and `web` guards; central routes are bound to the central domain, so they return 404 on tenant hosts | verified |
| Password reset | no Platform Admin reset flow exists (only CLI), so there is no email-based takeover path; tenant reset is Fortify's | unchanged |
| Session cookies | `HttpOnly` true, `SameSite=lax`, host-only; `Secure` follows `SESSION_SECURE_COOKIE` and **must be true in production** | documented |
| CSRF | all forms; single exemption `/support/handoff` (§4.2) | verified on the real server |
| Security headers | added `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`; HSTS only over HTTPS. **No CSP yet** (Livewire, Alpine and Flux need inline scripts and styles; nonces required) | added / documented |
| Public registration | none (Fortify registration disabled; no platform registration) | unchanged |

---

## 12. Livewire and Tenant Security Review

Protections from earlier milestones were **retested together with impersonation**:

| Earlier issue | Re-verified by |
|---|---|
| Tenant session storage and scoping (M1) | support/employee sessions live in the tenant DB; a support session on acme gives nothing on globex (`support access requires a separate session per tenant`) |
| Cross-tenant authentication (M1) | a credential for tenant A is refused and burned on tenant B; employees of another tenant cannot be targeted |
| Tenant permission caches (M2) | `a reused session immediately reflects changed permissions`; an impersonation in acme does not restrict globex in the same process |
| Stale tenancy models in long-running processes (M3) | suspension during an active session is detected even after reactivation; checks re-read the records |
| Persistent Livewire middleware (M4) | Livewire updates after expiry, revocation or deactivation are refused (403) |
| Subscription enforcement (M4) | support access cannot start without an active subscription; a session ends when the subscription stops being active |
| Sessions after suspension (M3) | suspension ends support sessions; the tenant stays 403 while suspended |

All checks use regular HTTP requests and real Livewire update requests with real component snapshots, in the same PHP process, which covers the long-running case.

### 12.1 Livewire file uploads (M3 finding)
- **Inspection:** `POST /livewire-<hash>/upload-file` and `GET /livewire-<hash>/preview-file/{name}` are registered by Livewire with the **`web` middleware only** (plus `throttle:60,1` on uploads). They run **outside the tenant context**: files would land in central storage whatever the host, and a signed URL is not bound to a tenant. No component uses `WithFileUploads`, so no signature is issued legitimately. But once uploads exist, the endpoints would be a cross-tenant storage path.
- **Fix:** both endpoints are **disabled** (404) by the `RejectTemporaryUploads` middleware on Livewire's controllers (`TenancyServiceProvider`), on every host, even with a valid signature (tested on the tenant and central hosts, and on the real server).
- **Related:** Laravel's local-disk serving routes (`GET|PUT /storage/{path}`, `'serve' => true` on the `local` disk) also ran outside the tenant context. They were unused and are now off (`'serve' => false`).
- **When uploads are needed:** bind the upload and preview routes to the `web` + `tenant` groups (as done for the update route), use tenant-suffixed disks, and add tests.

---

## 13. Security Fixes

| # | Finding | Severity | Fix | Regression test |
|---|---|---|---|---|
| 1 | spatie/laravel-permission's `Gate::before` was registered first and returns `true` for permission-named abilities, so a later deny callback could never refuse them | High for impersonation (defense in depth) | its automatic registration is disabled (`config/permission.php`); the same package callback is registered right after the impersonation restriction | `backend actions refuse the impersonated user even when called directly` |
| 2 | `RequestSeatChange` checked `hasPermissionTo()` directly, bypassing every Gate hook | Medium | uses `$actor->cannot(...)` (the Gate) | same test; billing tests unchanged |
| 3 | Gates `manage-billing` (M4) and `TenantPolicy` (M3) relied on a `PlatformAdmin` type hint "to deny" other identities, but Laravel calls the callback for any authenticated user, so a tenant user caused a **TypeError (500)**. The same applied to `UserPolicy`/`RolePolicy` for Platform Admins. Not reachable through current routes (central routes are domain-bound) | Low (fail-closed with an error) | explicit `instanceof` checks; policies deny other identities in `before()` | `platform and tenant abilities deny the other kind of identity instead of failing` |
| 4 | Livewire upload/preview routes outside the tenant context | Medium (latent) | disabled (§12.1) | `livewire temporary uploads are refused on every host…` |
| 5 | Local-disk serving routes outside the tenant context | Low (latent) | `'serve' => false` | `private local files are not served over HTTP` |
| 6 | No security headers | Low | `SecurityHeaders` middleware | `responses carry baseline security headers`, `HSTS is sent only over HTTPS` |
| 7 | The last active Tenant Admin could delete their own account (Starter Kit feature), leaving the company without an administrator | Medium | refused with a validation error | `the last active tenant admin cannot delete their own account` |
| 8 | "Remember me" for Platform Admins (long-lived cookie for the most privileged guard) | Low | removed | `without two-factor the password logs in directly…` |

---

## 14. Database Migrations

All additive; nothing existing is altered destructively.

**Central (`database/migrations`):**
- `2026_10_21_100000_create_support_sessions_table`: `support_sessions` and `support_session_events` (FK to `tenants` restrict, to `platform_admins` null on delete, events cascade with their session).
- `2026_10_21_100100_add_two_factor_columns_to_platform_admins_table`: `two_factor_secret`, `two_factor_recovery_codes` (encrypted text), `two_factor_confirmed_at`, `two_factor_last_timestep`.

**Tenant (`database/migrations/tenant`):**
- `2026_10_21_100000_create_impersonation_sessions_table`: `impersonation_sessions` and `impersonation_events` (identities copied, no FK to `users`).
- `2026_10_21_100100_create_notifications_table`: Laravel's standard `notifications` table.

**Existing environments:**
- `php artisan migrate` (central).
- `php artisan tenants:migrate` (every tenant database).
- No authorization sync is needed: no new permissions were added. Access to the new tenant pages is role-based (Tenant Admin).

---

## 15. New or Modified Files

### New
- **Config:** `config/impersonation.php`
- **Enums:** `app/Enums/ImpersonationKind.php`, `ImpersonationStatus.php`, `ImpersonationEndReason.php`
- **Impersonation:** `app/Impersonation/ActiveImpersonation.php`, `Impersonator.php`, `RestrictionPolicy.php`, `EmployeeImpersonationRules.php`
- **Actions:** `app/Actions/Impersonation/StartSupportSession.php`, `ConsumeSupportHandoff.php`, `StartEmployeeImpersonation.php`, `RevokeImpersonation.php`, `EndImpersonationsFor.php`
- **Auth:** `app/Auth/PlatformTwoFactor.php`
- **Console:** `app/Console/Commands/ResetPlatformAdminTwoFactor.php` (`platform:reset-two-factor`)
- **Exceptions:** `app/Exceptions/ImpersonationRefused.php`
- **Controllers:**
  - `app/Http/Controllers/Platform/SupportSessionController.php`, `TwoFactorController.php`, `TwoFactorChallengeController.php`
  - `app/Http/Controllers/Tenant/SupportHandoffController.php`, `EndImpersonationController.php`
- **Middleware:** `app/Http/Middleware/EnforceImpersonation.php`, `SecurityHeaders.php`, `RejectTemporaryUploads.php`
- **Models:** `app/Models/SupportSession.php`, `SupportSessionEvent.php`, `ImpersonationSession.php`, `ImpersonationEvent.php`, `Concerns/IsImpersonationSession.php`
- **Notifications:** `app/Notifications/SupportAccessStarted.php`
- **Providers:** `app/Providers/ImpersonationServiceProvider.php`
- **Views:**
  - `platform/support-sessions/{index,show,create,handoff,partials/table}`
  - `platform/account/{security,recovery-codes}`, `platform/auth/two-factor-challenge`
  - `partials/impersonation-banner`, `support-handoff-failed`, `pages/access-history/⚡index`
- **Migrations:** see §14.
- **Tests:**
  - `tests/Feature/Impersonation/SupportAccessTest.php`, `EmployeeImpersonationTest.php`, `RestrictionPolicyTest.php`
  - `tests/Feature/Platform/PlatformTwoFactorTest.php`, `tests/Feature/SecurityHardeningTest.php`

### Modified
- **Bootstrap and routes:** `bootstrap/app.php` (middleware group and priority, security headers, CSRF exemption), `bootstrap/providers.php`, `routes/web.php`, `routes/tenant.php`.
- **Config:**
  - `config/permission.php` (gate registration order);
  - `config/filesystems.php` (`serve` off);
  - `.env.example` (new settings);
  - `phpunit.xml` (pins the impersonation flags for tests).
- **Providers:**
  - `app/Providers/AppServiceProvider.php` (`manage-billing` gate);
  - `app/Providers/TenancyServiceProvider.php` (uploads disabled).
- **Policies:** `app/Policies/TenantPolicy.php`, `UserPolicy.php`, `RolePolicy.php` (`before()`).
- **Actions:**
  - `app/Actions/Billing/RequestSeatChange.php` (Gate);
  - `app/Actions/Platform/SuspendTenant.php` and `app/Actions/Employees/SetEmployeeActivation.php` (end impersonations).
- **Models:**
  - `app/Models/PlatformAdmin.php` (2FA);
  - `app/Models/PlatformAuditLog.php` (`recordSystem`, attribution guard);
  - `app/Enums/PlatformAuditAction.php`.
- **Middleware:** `app/Http/Middleware/EnsurePasswordIsChanged.php`.
- **Platform authentication:** `app/Http/Controllers/Platform/AuthenticatedSessionController.php`, `app/Http/Requests/Platform/LoginRequest.php` (2FA step, no remember-me).
- **Platform pages:** `app/Http/Controllers/Platform/TenantController.php`.
- **Views:**
  - `layouts/app`, `layouts/app/sidebar`, `layouts/platform`, `dashboard`;
  - `platform/auth/login`, `platform/tenants/show`, `platform/tenants/partials/audit-entries`;
  - `pages/employees/⚡index`, `pages/settings/⚡delete-user-modal`.
- **Tests:** `tests/Pest.php` (helpers).

---

## 16. Packages

**None added.** The 2FA implementation uses `pragmarx/google2fa` and `bacon/bacon-qr-code`, already installed as dependencies of `laravel/fortify`. `composer.json` and `composer.lock` are unchanged.

---

## 17. Actual Tests and Results

### 17.1 Automated tests (Pest, isolated databases `fcl_central_testing` + `fcl_test_tenant_*`)

| File | Tests | Covers |
|---|---|---|
| `Impersonation/SupportAccessTest` | 36 | disabled by default; guests and tenant users refused; start page targets; password re-entry; rate limiting; reason; fresh 2FA code and its replay; POST hand-over (no-store, no-referrer, hashed); audit + notifications; restricted session as the selected admin; replay; expiry of the credential; malformed credentials; tenant A credential on tenant B (burned); separate session per tenant; suspension (before and during, also after reactivation); not-ready tenants; subscription none/expired; inactive or non-admin targets; target deactivated; time limit; end; logout; revocation (platform and tenant side); scoped history and notices; no credential in stored data; central routes unreachable |
| `Impersonation/EmployeeImpersonationTest` | 20 | view as employee; employee permissions apply and the account is unchanged; password required and rate limited; reason; only Tenant Admins; no Tenant Admins / equal privileges / inactive / self (also with tampered Livewire payload); other tenant's users; end restores the admin with a new session id; time limit (page and Livewire); employee deactivated; initiator demoted; ended by another admin; nested and circular refused; tenant-local audit and events; feature flag (including running views); temporary password not forced |
| `Impersonation/RestrictionPolicyTest` | 32 | read-only pages; access history refused; 14 Livewire operations refused, recorded and without any change; non-GET HTTP refused and recorded; platform/billing endpoints unreachable; backend actions refuse when called directly; unreviewed component refuses updates and methods; no leak into later requests or another tenant in the same process; changed permissions apply to a reused session |
| `Platform/PlatformTwoFactorTest` | 12 | login without 2FA and no remember-me; enrollment; encryption at rest; password alone does not log in; valid code; replay and older codes refused; recovery code single use; lockout of the pending login; pending login expiry; disable; regenerate codes; console reset (audited) |
| `SecurityHardeningTest` | 8 | uploads refused on tenant and central hosts with a valid signature; local files not served; security headers (central, tenant); HSTS only over HTTPS; last Tenant Admin cannot delete their account; cross-identity abilities deny without errors |

**108 new tests.** Full suite: see §17.3.

### 17.2 Real-server verification (`php -S`, curl with separate cookie jars per browser, testing databases only)

`APP_ENV=local`, `SUPPORT_ACCESS_ENABLED=true`, `SUPPORT_REQUIRE_TWO_FACTOR=true`, one Platform Admin with a real TOTP secret, tenants `demo` and `demo2` provisioned over HTTP. All data was removed afterwards (only `fcl_central` and `fcl_central_testing` remain).

| # | Check | Result |
|---|---|---|
| 1–3 | Password step → challenge; dashboard before the code → login; code → dashboard | 302 challenge; 302 login; 302 `/admin` |
| 4 | Same code replayed in another browser | refused (back to the challenge), dashboard 302 login |
| 5 | "Remember me" on the platform login | absent |
| 7–8 | Start page lists the Tenant Admin; wrong password | listed; refused, 0 sessions |
| 9 | Start with password + fresh code | 64-char credential, `no-store`, `no-referrer`, POST form, credential in no URL |
| 10–12 | Hand-over in the same browser; tenant dashboard; central session | 302 `/dashboard`; 200 with banner; central still 200 (host-only cookies) |
| 13 | Credential replayed from another browser | 403 |
| 14 | **6 parallel hand-overs with one credential** | **1 accepted, 5 refused** |
| 15 | demo credential on demo2, then on demo | 403, then 403 (burned) |
| 16–18 | Livewire: deactivate employee, change password, request seats | 403, 403, 403; employee still active; 0 seat requests |
| 19 | Livewire search (allowed property update) | 200 |
| 20–22 | POST confirm-password; access history; `/admin/plans` on the tenant host | 403; 403; 404 |
| 23–24 | Platform revokes; support browser's next request | revoked; 302 login (its stale Livewire request: 419, CSRF token gone with the session) |
| 25–26 | New session; time limit passed (moved in the database) | 302/200; then 302 login, `end_reason=expired` |
| 27–28 | Suspend during a session; reactivate | 403 while suspended; after reactivation 302 login, `end_reason=tenant_suspended` |
| 29–30 | Tenant Admin login; support notice | dashboard shows the notice; 5 notifications (one per session) |
| 31–33 | View as clerk with a wrong password; with the right password | refused, 0 sessions; started, **session id changed**, banner "Viewing as Clara Clerk", roles page 403 |
| 34–36 | Nested view; End impersonation; tenant record | 403; 302 `/employees` as the admin, no banner; `admin@demo.test -> clerk@demo.test ended_by_actor` |
| 38 | Security headers on a tenant response | 4/4 |
| 39 | Tenant login without a CSRF token | 419 (CSRF still enforced) |
| 40 | Credential or its hash in audit, sessions, events, notifications | 0 occurrences |
| 41 | Support audit trail | `session_started` ×5 [platform_admin], `session_revoked` [platform_admin], `session_ended` ×3 [system] |
| 42 | Support activity events | viewed 8, denied 4 |
| — | Server log errors / new `laravel.log` entries | 0 / 0 |

**Not verified on the real server:** the Livewire upload endpoint. The request was rejected by CSRF (419) first, because the token used had just been rotated by "End impersonation". The 404 with a valid signature is covered by `SecurityHardeningTest` on both hosts.

**Incident during verification:** the first smoke run used port 8002, which is already used by another local project's development server (ConvoOps, `artisan serve`). The test server could not bind, so that run's curl requests (login attempts, page loads and form posts to `/admin/...` and `demo.localhost:8002`) reached that other application instead. That project was not otherwise touched, its server process kept running, and the run was discarded. The script now refuses to start on a busy port and stops only its own server process.

**Found during verification (pre-existing since Milestone 1, not fixed):** `GET /user/confirm-password` returns 500 (`ConfirmPasswordViewResponse` not bound: Fortify's confirm-password view was never registered). Nothing in the application links to it. Register a view or remove the route in a later milestone.

### 17.3 Final verification run

Run on the final working tree before committing (the owner's uncommitted `CreatePlatformAdmin.php` change was present in the working tree; it does not affect these tests):

| Command | Result |
|---|---|
| `composer test` (config clear, Pint, PHPStan level 7, Pest) | Pint **passed**, PHPStan **0 errors**, Pest **388 tests: 387 passed, 1 skipped**, **1,734 assertions** |
| `php artisan test` | 388 tests: 387 passed, 1 skipped (Starter Kit tenant two-factor test; tenant 2FA is disabled) |
| `npm run build` | built successfully |
| `npm audit` | 0 vulnerabilities |
| `composer audit` | no security advisories |

Only the final state was tested; the individual commits were not tested one by one.

---

## 18. Manual Browser Verification Steps

Not performed by the developer (no browser tool was available). Run them locally:

**Setup**
1. `php artisan migrate` and `php artisan tenants:migrate`.
2. In `.env`: `SUPPORT_ACCESS_ENABLED=true` (and keep `SUPPORT_REQUIRE_TWO_FACTOR=true`).
3. `php artisan config:clear`, then `php artisan serve`.
4. Have a ready, subscribed tenant (e.g. `acme`) with its Tenant Admin and one employee with the default **Employee** role.

**Platform Admin 2FA**
5. Log in at `http://localhost:8000/admin/login`. "Remember me" is gone. Click your email (top right) → **Account security** → enter your password → **Set up**.
6. Scan the QR code with an authenticator app, enter the code → **Confirm**. 8 recovery codes are shown once; store one.
7. Log out and log in again: after the password you must enter a code. Try the **same** code again in a private window: it is refused.

**Support session**
8. **Tenants → acme → Start support session.** The page shows the company and its active Tenant Admins. Submit without a reason or with a wrong password: refused.
9. Enter the reason, your password and a **new** code → **Start support session**. The browser moves to `acme.localhost:8000/dashboard` with an **amber banner**: "Impersonation active · Platform support: you", "Viewing as …", end time and countdown, **End impersonation**.
10. Browse **Employees**, **Roles**, **Subscription**: pages open. Try **New employee**, **Deactivate**, a role change, a seat request, and **Settings → Profile/Password** saves. Each must be refused: Livewire shows an error, and nothing changes after a reload. **Access history** returns 403.
11. In another tab, the central admin is still logged in. **Support** shows the session as *Active*; open it to see the pages viewed and the refused operations.
12. Click **End impersonation**: you land on the central session details (*Ended*); the tenant tab is logged out.
13. Start another session, then **Revoke** it from **Support** in the central tab. Reload the tenant tab: back to the login page.
14. Log in to acme as the Tenant Admin: the dashboard shows the support notice. **Access history** lists the sessions with the reason; **Mark as read**.

**Employee view**
15. As the Tenant Admin: **Employees → View as** on the employee. Enter a reason and your password → **Start**. The banner shows "Tenant Admin: …", "Viewing as …". **Roles** is 403 (the employee cannot see it).
16. Try any change: refused. Click **End impersonation**: you are back on **Employees** as yourself.
17. **View as** is not offered for yourself, other Tenant Admins or inactive users.

**Expiry and conditions** (optional): set `SUPPORT_SESSION_LIFETIME=1` and `EMPLOYEE_IMPERSONATION_LIFETIME=1`, run `config:clear`, start a session and wait one minute. The next click ends it. Suspending acme during a support session also ends it.

---

## 19. Remaining Limitations

- **Owner decision pending:** the Platform Admin password minimum (§11.1).
- **2FA is optional for Platform Admin logins** (required only for support sessions). Mandatory enrollment is a policy decision.
- **Tenant users have no 2FA** (Fortify 2FA stays disabled for tenants, as in the Starter Kit baseline).
- **No Content Security Policy** (needs nonces for Livewire, Alpine and Flux).
- **Single Platform Admin role:** every Platform Admin may start support sessions in every tenant (authorization is per session, not per tenant assignment). Platform roles would be needed for least privilege.
- **Lifetime counts from creation**; there is no extension. A new session needs a new reason and re-authentication.
- **Expiry is enforced on the next request.** An idle browser keeps showing the last page until it makes a request; the banner countdown shows the end.
- **Viewing is recorded per page load** (route name), not per Livewire render or per record viewed.
- **Notifications are in-app only** (no email).
- **No browser-automation tests**; Livewire behavior was verified with real update requests over HTTP and on the real server.

---

## 20. Deferred Features

- Mandatory Platform Admin 2FA, platform roles, per-tenant support assignment.
- Approval by the tenant before support access (consent workflow).
- Email notifications of support access.
- Tenant-aware Livewire file uploads (when a feature needs them).
- Content Security Policy with nonces.
- Tenant user 2FA.
- FCL Operations (out of scope; the impersonation allowlist denies them by default).
