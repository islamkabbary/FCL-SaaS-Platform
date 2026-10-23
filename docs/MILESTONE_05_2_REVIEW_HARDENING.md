# Milestone 5.2 — Review Hardening

This document describes what was **actually implemented and verified** in Milestone 5.2 (2026-10-23). The milestone fixes every finding of the deep review of 2026-10-23 before FCL M1-A5 starts. That review was a re-review of the whole repository on the second development computer: four independent reviewers, each finding re-checked in code, six proven with temporary tests.

It builds on [Milestone 5.1](MILESTONE_05_1_SECURITY_BILLING_HARDENING.md). The overall assessment is in [SAAS_MVP_READINESS.md](SAAS_MVP_READINESS.md) §9.

> **Not a penetration test.** "Verified" means covered by automated Pest tests. Every fix has a permanent regression test, and each new test file was run once against the code **without** its fix and confirmed to fail (controls excepted, §7). No real-server or browser run was done in this milestone (§9).

The review found **no Critical or High issue**, and tenant isolation held in every path checked. It found 9 Medium and about 15 Low defects. All of them are fixed here, except the items listed in §8 as documented only.

---

## 1. Owner decisions (2026-10-23)

| # | Question | Decision |
|---|---|---|
| D1 | A colleague changes an employee's email | Still allowed, with protection. The email is unverified, the employee is signed out everywhere, and the old address is told |
| D2 | Platform Admin two-factor authentication | **Mandatory for login.** This closes the "Platform Admin MFA policy" production blocker |
| D3 | Payment date | Not earlier than the charge's issue date |
| D4 | Permissions removed or renamed in the catalog | Ignored by the privilege checks. The sync moves renamed grants and deletes retired permissions |

---

## 2. Overview

| Area | Before | After |
|---|---|---|
| Employee email changed by a colleague | Verified status and sessions kept, so taking over the account by password reset was possible | Unverified, signed out everywhere, old address notified |
| Own email change (profile) | No password needed | Current password required; old address notified; email lower-cased |
| Current-password checks (security page, account deletion, email) | Unlimited guesses | 5 guesses, then a 5-minute lock per tenant and user |
| `POST /login` with an array email | 500 | Validation error |
| Login for an unknown email (tenant, platform) | Returned before any hash check (timing reveals accounts) | Constant dummy bcrypt check |
| Platform login throttle | Per email + IP only | Also 20 failures per hour per account, any IP |
| Password reset | `must_change_password` stayed set | Cleared |
| Platform Admin 2FA | Optional for login; a password alone could enroll an attacker's authenticator and pass support's 2FA check | Required. Un-enrolled admins reach only enrollment and logout; it cannot be disabled; a support session ends if its admin loses 2FA |
| 2FA enabled, disabled or reset | Other browsers stayed signed in | Other browsers end; disabling or resetting also ends open support sessions |
| Access history (impersonation) | A client-chosen `X-Livewire` header hid page views; a refused page showed as "viewed" | Every GET is recorded after handling: viewed, or denied (403) once |
| Impersonating browser's session row | Carried the target's `user_id` | Carries the real owner (Tenant Admin for employee view, none for support) |
| Allowlisted pagination calls | Ran `updating*`/`updated*` hooks with a client-chosen name | Numeric page and default paginator only |
| FCL detection | Missed `pages::fcl`, `*::fcl.*` and FCL resources inside arrays or collections | Covered |
| Support-start notice | `Tenant::run()` without `finally` | Context always restored; failure reported |
| Renewal from a stale form | Silently dropped seats bought after the preview | Refused when the current term's seats or the scheduled reduction changed |
| Scheduled reduction at renewal | Marked Applied whatever the seats | Applied only with its seats; otherwise Superseded, with an audit reason |
| Seat-increase estimate with a confirmed next term | Omitted the full-year line (6.58 shown, 246.58 charged) | Same calculation as the approval, each line listed |
| Stale reduction approval | Approvable in a later term | Must belong to the current term and still reduce |
| Payment | Any past date; one token on two charges at once gave a 500 | Not before the issue date; a friendly error |
| Renewal preview with an unknown plan | Fell back silently to the current plan | Validation error |
| Retired permission | Made roles and holders untouchable, even by Tenant Admins | Ignored by the guard; the sync moves renames and deletes retired ones |
| Custom role named "Tenant Admin" (rename, look-alikes) | Possible | Refused; names stored normalised |
| Demoted admin waiting for the lock | Finished deactivations and role changes with old privileges | Re-read and re-authorized after the lock |
| Default branch | Found by code `MAIN` with `sole()` | Found by `is_default`, falling back to the code |
| Append-only models | `truncate()`, `updateOrInsert()`, and bulk update/delete on platform audit, billing and event models | Refused by `AppendOnlyBuilder` on every append-only model |
| `TenantAuditLog` | Could be forged with `create()` / `replicate()`; redaction missed several keys and Collections | Only through `record()`; wider redaction, including Collections and JsonSerializable values |
| `tenant_audit_logs.subject_type` | 100 characters | 255 (tenant migration) |

---

## 3. Commits (on `main`, not pushed)

| Hash | Commit |
|---|---|
| `c61938a` | `fix(auth): protect login email changes against account takeover` |
| `10cf7aa` | `fix(auth): rate limit current-password checks in the settings pages` |
| `6ebdd16` | `refactor(auth): make the current-password check an injected class` |
| `64cde3d` | `fix(auth): harden login throttles and timing; clear the reset flag` |
| `fb9650b` | `feat(platform): require two-factor authentication for every Platform Admin` |
| `e83afff` | `fix(platform): end other sessions and support access on 2FA changes` |
| `64b1d1f` | `fix(impersonation): record every page view, after authorization` |
| `5c6e82b` | `fix(sessions): attribute impersonating browsers to their real owner` |
| `0896e88` | `fix(impersonation): restrict pagination calls and widen FCL detection` |
| `fffc775` | `fix(billing): refuse stale renewals and record reductions accurately` |
| `2463e1b` | `fix(billing): include confirmed later terms in the seat-increase estimate` |
| `0b9ff04` | `fix(billing): re-check stale reductions; bound payment dates; check the plan` |
| `ad4be5e` | `fix(roles): ignore retired permissions and reserve role names` |
| `1772d82` | `fix(employees): re-authorize after the lock; find the default branch safely` |
| `1f7786f` | `fix(audit): close the remaining append-only and redaction gaps` |
| (last) | `docs(hardening): document Milestone 5.2 review hardening` |

`6ebdd16` is a follow-up. The trait added in `10cf7aa` was used only by single-file Livewire pages, which PHPStan does not analyse, so it was turned into an injected class.

---

## 4. Implementation notes per area

### 4.1 Accounts and authentication

- `App\Actions\Employees\UpdateEmployee::handle()` now returns whether the email changed. When it did, the action:
  - clears `email_verified_at`;
  - calls `EndOtherSessions`, which rotates the remember-me token and deletes the sessions (the actor's own current session is kept when they edit themselves);
  - after the commit, sends `App\Notifications\EmailAddressChanged` (mail) to the **old** address.
- The profile page (`pages::settings.profile`) lower-cases and trims the email. A changed email needs `current_password`.
- `App\Auth\CurrentPasswordConfirmation::ensure()` is used by the security page, the delete-account modal and the profile. Key: `current-password|{tenant}|{user}`. Limits: 5 attempts, 300 seconds.
- `App\Auth\DummyPasswordCheck` holds a bcrypt hash at cost 12 of a random value. It is checked whenever the account is unknown, in Fortify's `authenticateUsing` and in `Platform\LoginRequest`.
- The Fortify `login` limiter no longer converts a non-string email (it runs before validation).

### 4.2 Platform Admins

- New `config/platform.php`: `require_two_factor` (`PLATFORM_REQUIRE_TWO_FACTOR`, default **true**).
- New middleware `EnsurePlatformTwoFactor` (alias `platform.two-factor`) on the whole `auth:platform` group.
  - Allowed without 2FA: `platform.account.security`, `platform.account.two-factor.enable`, `platform.account.two-factor.confirm`, `platform.logout`.
  - Everything else redirects to Account security; JSON requests get a 403.
- Disabling 2FA is refused while it is required. Recovery: `php artisan platform:reset-two-factor {email}`.
- `Impersonator::terminationReason()` ends a support session (`actor_unauthorized`) when its admin has no 2FA while 2FA is required, by `platform.require_two_factor` or `impersonation.support.require_two_factor`.
- New `App\Actions\Platform\EndPlatformAdminAccess`. It deletes the admin's other **central** session rows, which carry the admin's id on authenticated platform routes. Optionally it also ends their open support sessions. Used on 2FA confirm, disable and the CLI reset.
- `phpunit.xml` sets `PLATFORM_REQUIRE_TWO_FACTOR=false`, so existing platform tests can sign in without enrolling. The requirement has its own tests.

### 4.3 Impersonation

- `EnforceImpersonation` records GETs **after** `$next()`:
  - a response below 400 is recorded as `viewed`;
  - a 403 is recorded as `denied`, unless a deeper layer already recorded a refusal during the request (`ActiveImpersonation::DeniedRecordedAttribute`).
- Refusals of individual abilities inside a page (hidden menu items) are deliberately **not** recorded.
- `TenantAwareDatabaseSessionHandler::userId()` stores the real owner while the session carries the `Impersonator::SessionKey` marker:
  - an employee view stores the impersonating Tenant Admin's id;
  - a support session stores `null`.

  As a result:
  - deactivating the viewed employee, or the employee changing their password, no longer logs the Tenant Admin out (they are returned to their own account, as intended);
  - signing the Tenant Admin out everywhere also ends their impersonating browser.
- `RestrictionPolicy::allowsLivewireCall($method, $params)`: pagination methods are allowed only with an integer (or digit string) page, the paginator name `page`, and no extra parameters.
- `RestrictionPolicy::deniesComponent()` also matches `pages::fcl` exactly and any `::fcl.` segment.
- `allowsAbility()` checks iterable arguments recursively.
- `StartSupportSession` sends the notice with an explicit `initialize` and a `finally` that restores the previous tenant context. A failure is `report()`ed.

### 4.4 Billing

- The renewal form posts `expected_previous_seats` and `expected_reduction_id` (0 for none). Both are required by `SubscriptionController::renew` and re-checked by `RenewSubscription::handle()` under the subscription lock.
- A reduction is `Applied` (with `applied_to_term_id`) only if the new term's seats equal its seats. Otherwise it is `Superseded`, and the renewal's audit reason says so.
- New `App\Billing\SeatIncreaseQuote` (`lines()`, `total()`, `futureTerms()`). It is shared by:
  - `ReviewSeatChangeRequest::quote()`;
  - the stored `estimated_amount` of `RequestSeatChange`;
  - the tenant page `increaseEstimate`, which lists each line when there is more than one.
- `ReviewSeatChangeRequest::approve()` for a reduction requires `subscription_term_id` to be the current term and `seats < currentTerm.seats`.
- `RecordPayment`:
  - `paid_on >= issued_on`;
  - a `UniqueConstraintViolationException` on `submission_token` becomes the existing friendly message.
- The renewal preview validates `plan_id` with `exists:subscription_plans,id`.

### 4.5 Roles, employees

- `PrivilegeGuard` intersects every permission set with `TenantPermission::values()`.
- `TenantPermission::renamed()` maps old names to new ones; it is empty today. `SyncTenantAuthorization`:
  1. applies renames first: renamed in place, or merged, moving the role grants and the direct user grants;
  2. creates the catalog;
  3. deletes non-catalog permissions (the foreign keys cascade their grants).
- `TenantRole::normalizeName()` and `isReservedName()` (ignoring case and whitespace). `SaveRole` refuses a reserved name for any custom role, whether created or renamed. The roles page normalises the name before its unique check.
- `UpdateEmployee` (with roles) and `SetEmployeeActivation` (deactivation) re-read the actor and target after `User::activeTenantAdminCount()` and authorize again.
- `CreateEmployee::defaultBranchId()` uses `is_default`, then `Branch::DefaultCode`.

### 4.6 Audit

- `AppendOnlyBuilder` also refuses `truncate()` and `updateOrInsert()`. It is now attached with `#[UseEloquentBuilder]` to:
  - `PlatformAuditLog`;
  - `BillingCharge`, `BillingChargeLine`, `BillingPayment`;
  - `ImpersonationEvent`, `SupportSessionEvent`;
  - `TenantAuditLog` (as before).
- `TenantAuditLog` refuses inserts outside `record()`, through a `creating` guard and a private static flag.
- The redaction pattern now also covers `recovery`, `private_key`, `authorization`, `bearer`, `passcode` / `passwd` / `passphrase` and `card_number`. Standalone segments `pwd`, `pass`, `otp`, `pin`, `cvv`, `cvc` and `iban` are redacted too, and so is `session[_id|_key]` in any case.
  - Ordinary words are kept: `shipping_line`, `pinned`, `passenger_count`, `impersonation_session_id`.
  - `Arrayable` and `JsonSerializable` values are converted to arrays and redacted.
  - JSON-encoded strings are **not** inspected; pass arrays.
- **Still outside the guarantee:** raw SQL (`DB::table()`, `toBase()`) and direct database access.

---

## 5. Migrations

| Migration | Connection | Effect |
|---|---|---|
| `database/migrations/tenant/2026_10_23_100000_widen_subject_type_of_tenant_audit_logs.php` | tenant | `tenant_audit_logs.subject_type` from `varchar(100)` to `varchar(255)` |

**Deployment:**
1. `php artisan tenants:migrate`.
2. `php artisan tenants:sync-authorization`, which now also deletes permissions no longer in the catalog. None exist today.

No central migration. No new package.

---

## 6. Configuration

| Key | Default | Notes |
|---|---|---|
| `PLATFORM_REQUIRE_TWO_FACTOR` (`config/platform.php`) | `true` | Added to `.env.example`. Set to `false` only in tests (`phpunit.xml`) |

**Rollout:** an existing Platform Admin without 2FA is sent to enrollment on their next login. Keep an operator with server access available (`platform:reset-two-factor`).

---

## 7. Tests

New files (each run once with its fix reverted):

| File | Tests | Without the fix |
|---|---|---|
| `tests/Feature/Hardening/AccountTakeoverTest.php` | 7 | 6 fail (name-only control passes) |
| `tests/Feature/Hardening/CurrentPasswordThrottleTest.php` | 4 | 3 fail (control passes) |
| `tests/Feature/Hardening/AuthenticationHardeningTest.php` | 6 | 5 fail (dummy-hash constant check passes) |
| `tests/Feature/Hardening/PlatformTwoFactorRequirementTest.php` | 11 | 8 fail (controls pass) |
| `tests/Feature/Hardening/PlatformSessionHardeningTest.php` | 3 | 3 fail |
| `tests/Feature/Hardening/ImpersonationHistoryTest.php` | 3 | 3 fail |
| `tests/Feature/Hardening/SessionOwnershipTest.php` | 5 | 5 fail (database session driver) |
| `tests/Feature/Hardening/ImpersonationRestrictionHardeningTest.php` | 26 | 13 fail (the 13 controls pass) |
| `tests/Feature/Billing/RenewalHardeningTest.php` | 5 | 4 fail (form-content check passes) |
| `tests/Feature/Billing/SeatEstimateHardeningTest.php` | 2 | 1 fails (control passes) |
| `tests/Feature/Billing/BillingReviewHardeningTest.php` | 6 | 5 fail (control passes) |
| `tests/Feature/Hardening/RolePermissionHardeningTest.php` | 11 | 11 fail |
| `tests/Feature/Hardening/EmployeeHardeningTest.php` | 3 | 3 fail |
| `tests/Feature/Hardening/AuditIntegrityTest.php` | 15 | 13 fail (control and migration check pass) |

New helpers in `tests/Pest.php`:
- `storeSessionRow()`;
- `useDatabaseSessions()`, which switches one test to the database session driver (phpunit uses `array`, which hid M4).

**Changed existing tests:**
- `ProfileUpdateTest` supplies the current password.
- `RenewalTest` posts the two new renewal fields.
- `BillingPaymentTest` fixture payments move from the day before the issue date to the issue date (D3).

**Full suite:** 585 tests (107 more than before M5.2), see §9.

---

## 8. Known limitations (documented only)

- **Raw SQL** can still change append-only tables. Tamper evidence against direct database access is a production topic.
- **`__Host-` cookie prefix:** recommended for production (`SESSION_COOKIE=__Host-...` with HTTPS and an empty `SESSION_DOMAIN`), so a sibling tenant subdomain cannot plant cookies. See readiness §4.
- **Reset-link timing:** the reset notification is sent synchronously only for existing accounts, so mail latency may reveal accounts. Queue it once queues are in use.
- **Livewire snapshots** are signed with the shared `APP_KEY` and are not bound to a tenant. No exploitable path exists today (authorization and models re-resolve in the receiving tenant). Future components holding central models must not trust them.
- **`TenantAuditLog`** cannot tell that a subject was loaded under another tenant (all use the `tenant` connection). Rule: record inside the tenant that owns the subject.
- **Lazy Livewire components** are mounted by Livewire before the impersonation listener can refuse `__lazyLoad`. None exist, and a test fails if one is added. Review this before using `#[Lazy]`.
- **Renewal seat count:** still read outside the per-tenant seat lock (the documented M4 §21 race).
- **Retired permissions** are deleted by the sync. A permission that is meant to be **renamed** must be listed in `TenantPermission::renamed()` in the same release, or its grants are lost.

---

## 9. Verification

| Check | Result |
|---|---|
| `composer test` (Pint, PHPStan level 7, Pest) | Pint passed · PHPStan level 7, 0 errors · **Pest 585 tests: 584 passed, 1 skipped** (the tenant 2FA starter test, as before), 2,479 assertions (2026-10-23). Before M5.2: 478 tests, 2,096 assertions |
| Protection-removal checks | Done for every new test file (§7) |
| Real-server / browser | **Not done** |

The test runs used a temporary in-memory `APP_KEY`, because this computer's `.env` has none. Add one with `php artisan key:generate` after adding an empty `APP_KEY=` line.

**Manual browser steps (owner):**
1. Platform: log in as an admin without 2FA. You can only reach Account security. Set up 2FA. A second browser that was signed in before must be signed out.
2. Tenant: as Tenant Admin, change an employee's email. The toast says the employee was signed out, and the old address receives the notice (in `storage/logs/laravel.log` locally).
3. Profile: changing your own email asks for your current password; five wrong tries lock it for five minutes.
4. Employee view: a second Tenant Admin deactivates the viewed employee. The first admin's next click returns them to their own account and does not log them out.
5. Support access: open pages, then try the Access history. The history lists each page as viewed, and the Access history as denied.
6. Subscription: renew early, then request seats. The estimate lists the current term **and** the renewed term.
7. Platform renewal: open the renewal form, approve a seat increase in another tab, then confirm the old form. It is refused with "review the renewal again".

The browser steps of Milestones 5 (§18) and 5.1 (§9) are also still outstanding.
