# SaaS Management MVP — Readiness Assessment (Milestones 1–5)

Assessment date: 2026-10-19, after Milestone 5; **updated 2026-10-20 after Milestone 5.1** (security and billing hardening, see §8) and **2026-10-23 after Milestone 5.2** (review hardening, see §9). Scope: the SaaS management layer:
- central platform;
- tenant provisioning and isolation;
- tenant users and permissions;
- subscriptions and billing;
- support access.

FCL Import Operations are not built yet and are not assessed.

> **Not a penetration test.** This is an evidence-based engineering review: automated tests (427 after Milestone 5.1), a deep code review with runtime proofs (2026-10-19), a real-server run with real cookies and sessions, and code inspection. No independent security audit or penetration test was performed. Passing tests does not mean production-ready.

**Statuses:** **PASS** — verified, no open issue. **PASS WITH LIMITATIONS** — works, with documented gaps that do not block this stage. **BLOCKED** — must be resolved before this stage. **NOT VERIFIED** — depends on infrastructure or decisions that do not exist yet.

---

## 1. What the MVP contains

| Milestone | Delivered | Documentation |
|---|---|---|
| 1 | Multi-tenant foundation: central DB + one DB per tenant, subdomain identification, provisioning, per-tenant sessions | [MILESTONE_01](MILESTONE_01_SAAS_FOUNDATION.md) |
| 2 | Tenant users, roles and permissions (spatie, tenant-scoped cache), privilege-escalation rules | [MILESTONE_02](MILESTONE_02_TENANT_USERS_PERMISSIONS.md) |
| 3 | Central platform: dashboard, tenant management, suspension/reactivation, audit trail | [MILESTONE_03](MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md) |
| 4 | Annual plans, subscriptions, seats, proration, manual billing and payments, subscription-based access | [MILESTONE_04](MILESTONE_04_SUBSCRIPTIONS_BILLING.md) |
| 5 | Restricted support access and employee view, Platform Admin 2FA, security fixes | [MILESTONE_05](MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md) |
| 5.1 | Fixes from the deep review: host validation, sessions after password changes, 2FA lockout, seat and billing integrity, concurrency | [MILESTONE_05_1](MILESTONE_05_1_SECURITY_BILLING_HARDENING.md) |

---

## 2. Summary

| Stage | Status | One-line reason |
|---|---|---|
| **A. Local development** | **PASS WITH LIMITATIONS** | Everything works and is tested locally; no browser automation, and M5 and M5.1 still need the owner's browser check |
| **B. Staging** (isolated, no real customers) | **PASS WITH LIMITATIONS**, conditional on the staging prerequisites in §4 | The code is ready to deploy; staging must provide HTTPS, wildcard DNS, production-like config and a restricted DB user |
| **C. Production** (real customers) | **BLOCKED** | Open owner decisions (mandatory admin 2FA, commercial policies) and missing operations (backups, monitoring, deployment and rollback, queued provisioning) |

---

## 3. A. Local Development Readiness — PASS WITH LIMITATIONS

| Area | Status | Evidence |
|---|---|---|
| Automated tests | PASS | After M5.1 (2026-10-20): `composer test` runs to the end (Composer's 300-second script timeout is disabled for it). Pint passed, PHPStan level 7 with 0 errors, Pest 427 tests (426 passed, 1 skipped: tenant 2FA starter test, feature disabled), 1,882 assertions. `npm run build` OK, `npm audit` 0, `composer audit` no advisories |
| Tenant isolation | PASS | separate databases; session scoping; cross-tenant login, credential and user lookups refused (tests M1–M5, real-server runs M3–M5) |
| Platform features (M3–M5) | PASS | feature tests plus real-server runs: M4 31 checks; M5 42 checks |
| Manual browser verification | PASS WITH LIMITATIONS | the owner completed browser smoke tests for M1–M4. **M5 and M5.1 have not been browser-tested yet** (steps in the M5 doc §18 and the M5.1 doc §9). No browser automation exists |
| Local database safety | PASS | the app uses `fcl_app`, which is limited to `fcl\_%` databases; tests use only `fcl_central_testing` and `fcl_test_tenant_*` |
| Working tree | PASS | clean (2026-10-20). The earlier uncommitted password-minimum change no longer exists: `platform:create-admin` requires `Password::min(12)`. The developer setup guide is in the root `README.md` |

---

## 4. B. Staging Readiness — PASS WITH LIMITATIONS (conditional)

The application can be deployed to an **isolated staging environment** once these prerequisites are in place. None of them needs code changes.

| Area | Status | Required for staging (specific to this codebase) |
|---|---|---|
| HTTPS and domains | NOT VERIFIED | Wildcard DNS and a wildcard TLS certificate for `*.CENTRAL_DOMAIN` (tenants are `{subdomain}.CENTRAL_DOMAIN`). Set `CENTRAL_DOMAIN` and `APP_URL=https://…`. Behind a load balancer, configure trusted proxies (`$middleware->trustProxies(...)` in `bootstrap/app.php`) with the **load balancer's own addresses only, never `at: '*'`**: a trusted `X-Forwarded-Host` decides the host of generated links such as password reset e-mails. Without trusted proxies HTTPS is not detected, HSTS is not sent and generated URLs use `http`. Outside `local`, `trustHosts` (M5.1) accepts only `CENTRAL_DOMAIN` and single-label subdomains of it |
| Environment | NOT VERIFIED | `APP_ENV=production` (enables `DB::prohibitDestructiveCommands` and the strict `Password::defaults()`), `APP_DEBUG=false`, a new `APP_KEY` (it encrypts the 2FA secrets: rotating it invalidates them), `LOG_LEVEL=warning` or higher |
| Sessions and cookies | NOT VERIFIED | `SESSION_SECURE_COOKIE=true`; keep `SESSION_DOMAIN` empty (host-only cookies keep the central and tenant sessions apart, which support access relies on). `SESSION_DRIVER=database` is required, because tenant sessions live in each tenant database. Recommended (M5.2): a `__Host-` cookie name (`SESSION_COOKIE=__Host-fcl_session`), so that script on a sibling tenant subdomain cannot plant a session cookie for another host. Every Platform Admin needs 2FA at first login (`PLATFORM_REQUIRE_TWO_FACTOR=true`) |
| Database user | NOT VERIFIED | a dedicated MySQL user limited to the central DB and the tenant prefix, with `CREATE` rights for provisioning. Never root. Locally this is already done (`fcl_app`) |
| Migrations | PASS WITH LIMITATIONS | `php artisan migrate --force`, then `php artisan tenants:migrate`, then `php artisan tenants:sync-authorization` (needed after M4). All M1–M5 migrations are additive |
| Support access | PASS WITH LIMITATIONS | off by default. Enable on staging (`SUPPORT_ACCESS_ENABLED=true`) to verify it; keep `SUPPORT_REQUIRE_TWO_FACTOR=true` |
| Mail | NOT VERIFIED | `MAIL_MAILER=log` writes password-reset links to `laravel.log`. Staging needs a real mailer or a capture service (e.g. Mailpit) |
| Queue and scheduler | PASS WITH LIMITATIONS | none required: provisioning is synchronous, subscription expiry and impersonation expiry are derived from dates, and notifications are stored synchronously |

---

## 5. C. Production Readiness — BLOCKED

### 5.1 Blockers (must be resolved before real customers)

| # | Area | Status | Evidence and required action |
|---|---|---|---|
| 1 | Platform Admin password policy | **Resolved** (2026-10-20) | The uncommitted `Password::min(6)` change is gone: the code and the working tree have `min(12)`. Optional: also require `Password::defaults()` in production. An admin created while the weaker rule was in place should rotate their password |
| 2 | Platform Admin MFA policy | **Resolved** (2026-10-23, M5.2) | Owner decision: 2FA is mandatory for every Platform Admin (`PLATFORM_REQUIRE_TWO_FACTOR=true`). Un-enrolled admins can only reach enrollment and logout; 2FA cannot be disabled while required; recovery is `platform:reset-two-factor` |
| 3 | **Backups and restore** | **BLOCKED** | Nothing exists. Every tenant has its own database (`fcl_tenant_<uuid>`), so backups must cover the central database **and** a dynamic list of tenant databases. Restores must be tested. Billing and audit records are append-only and must be included |
| 4 | **Monitoring and error reporting** | **BLOCKED** | Only the local `single` log file. Needed: central log shipping, an error tracker, uptime checks on central and a sample tenant, and alerts on provisioning failures (a tenant with `failed` status) |
| 5 | **Deployment and rollback** | **BLOCKED** | No procedure exists. Needed: a documented deploy (maintenance mode, `migrate --force`, `tenants:migrate`, `tenants:sync-authorization`, cache rebuild), a rollback plan (migrations are additive; restore from backup), and a tenant-migration failure policy |
| 6 | **Commercial and legal policies** | **BLOCKED** (business) | Open since M4: prices and currencies, tax/VAT, legal invoices and numbering, payment terms and dunning, grace period (currently none), late-renewal billing (currently the gap is not billed), refunds. Support access may also need customer terms (consent, notification wording) |
| 7 | **Secrets management** | **BLOCKED** | `.env` on the server; no secret store or rotation procedure. `APP_KEY` protects sessions, encrypted 2FA secrets and signed URLs and needs a secure, backed-up storage location |

### 5.2 Should be fixed before or soon after launch (not blocking a limited launch)

| Area | Status | Evidence and action |
|---|---|---|
| Synchronous provisioning | PASS WITH LIMITATIONS | Provisioning runs in the web request (M1). Fine for manual, low volume; move it to a queued job (needs a queue worker) for scale and resilience |
| Cache isolation | PASS WITH LIMITATIONS | `CacheTenancyBootstrapper` is off (the database cache has no tags). No tenant data is cached today. Rate-limiter keys include the tenant id where needed. Switch to Redis with the cache bootstrapper before caching tenant data |
| Content Security Policy | PASS WITH LIMITATIONS | Baseline headers are present (M5). There is no CSP; Livewire, Alpine and Flux need inline code, so nonces are required |
| Platform roles | PASS WITH LIMITATIONS | Every Platform Admin has every right, including support access to every tenant. Add platform roles for least privilege as the team grows |
| Tenant user MFA | PASS WITH LIMITATIONS | Tenant users have no 2FA (Fortify 2FA disabled since M1) |
| `GET /user/confirm-password` | PASS WITH LIMITATIONS | Returns 500 (no view registered since M1). Nothing links to it; register a view or drop the route |
| Self-service account deletion | PASS WITH LIMITATIONS | Starter Kit feature. The last Tenant Admin is protected (M5), also against concurrent deletions and demotions (M5.1), but users can still hard-delete their own account. Consider replacing it with deactivation by a Tenant Admin |
| Livewire uploads | PASS | Disabled until tenant-aware uploads exist (M5) |

### 5.3 Area-by-area evidence

| Area | Status | Evidence |
|---|---|---|
| Central vs tenant DB isolation | PASS | per-tenant databases; tenant models only ever use the tenant connection; central models use `CentralConnection`; cross-tenant tests in every milestone |
| Tenant identification | PASS | subdomain only, and since M5.1 only from a host that is exactly `{subdomain}.CENTRAL_DOMAIN` (hosts such as `acme.evilfcl.app` or `acme.other.fcl.app` return 404); `trustHosts` outside `local`; unknown hosts 404; central routes bound to the central domain (404 on tenant hosts, re-verified in M5) |
| Platform vs tenant guards | PASS | `platform` and `web` guards; host-only cookies; gates and policies now deny the other identity explicitly instead of throwing (M5 fix) |
| Permissions and privilege escalation | PASS | M2 privilege rules; M5 fixed the Gate ordering so a restriction can deny permission-named abilities |
| Session fixation and hijacking | PASS WITH LIMITATIONS | regeneration on login, 2FA, every impersonation start and end. Since M5.1 a password change or reset ends the user's other sessions and rotates the remember-me token, and ending an impersonation no longer rotates the account's remember-me token. HTTPS and `Secure` cookies still need to be configured (staging prerequisite) |
| Tenant suspension | PASS | blocks everything before the session (M3); ends support sessions (M5) |
| Subscription expiration | PASS | date-derived; re-applied to Livewire (M4); ends impersonation (M5) |
| Seat enforcement | PASS | MySQL named lock; 6-process race verified (M4). Since M5.1 also capped by the seats of an already confirmed later term |
| Billing and payment authorization | PASS | `manage-billing` (Platform Admins only); tenant `billing.view`/`billing.manage`; append-only records; refused during impersonation (M5). Since M5.1 activation, renewal and seat approval only charge the amount shown in the preview |
| Support access controls | PASS WITH LIMITATIONS | restricted, temporary, audited, revocable, off by default; see M5. Limitation: no per-tenant assignment, no tenant consent step |
| Audit integrity | PASS WITH LIMITATIONS | append-only through the application (model guards); actor attribution including `system`. Not tamper-proof against direct database access (no hashing chain or external log) |
| Rate limiting | PASS | tenant login (per tenant + email + IP), platform login, 2FA challenge (since M5.1 the lockout also survives a fresh password login), re-authentication, impersonation password, support hand-over, password reset links (per tenant + IP, M5.1) |
| CSRF | PASS | everywhere except the support hand-over, which uses a single-use credential (real server: 419 without a token) |
| Livewire action authorization | PASS | persistent middleware flushed per request (M4); impersonation checks on every call and update (M5) |
| Error handling and information disclosure | NOT VERIFIED | depends on `APP_DEBUG=false` in production. Provisioning errors shown to admins are redacted (M3) |
| Background jobs and CLI | PASS WITH LIMITATIONS | no tenant jobs yet. CLI commands (`tenants:*`, `platform:*`) need server access; `platform:reset-two-factor` is audited |
| Dependency security | PASS (as of 2026-10-19) | `npm audit` 0, `composer audit` none. The `tinypool` npm override (M1) is still needed until `vite-plus` is upgraded via `vp migrate` |
| Logging, monitoring, backups, deploy | BLOCKED | §5.1 |

---

## 6. Outstanding limitations from earlier milestones

| Item | Resolved? | Blocks local? | Blocks production? | Next step |
|---|---|---|---|---|
| Uncommitted Platform Admin password minimum (6) | **Yes**: gone, the code has `min(12)` (verified 2026-10-20) | No | No | None |
| `tinypool` npm override (M1) | No (still needed) | No | No (dev tooling only) | Upgrade `vite-plus` via `vp migrate`, drop the override |
| PHPStan scoped exception for `ProfileValidationRules` (M1) | No (false positive, still needed) | No | No | Remove when PHPStan analyses Livewire SFCs |
| Tenant cache isolation with Redis | Not needed yet | No | Only once tenant data is cached | Enable `CacheTenancyBootstrapper` with Redis |
| Synchronous provisioning | No | No | No for a small launch; yes at scale | Queued job plus worker |
| Historical tenant subscription exemptions (legacy tenants) | By design | No | Business decision | Activate subscriptions for legacy tenants, or confirm the exemption |
| No grace period after expiry | By design (technical default) | No | Business decision | Decide a grace period |
| Late-renewal handling (gap not billed) | By design (technical default) | No | Business decision | Decide the policy |
| Livewire upload routing (M3) | **Yes**: disabled (M5) | No | No | Tenant-aware uploads when needed |
| Platform Admin MFA (M1–M4: none) | **Partly**: TOTP available (M5) | No | **Yes, as a policy decision** | Make it mandatory |
| Intermittent test failure (M4, one unreproduced run) | Not reproduced since: every later full run passed, including 4 full runs in M5 | No | No | Watch CI once it exists |
| Tax, legal invoicing, payment terms | Open (M4) | No | **Yes** (business) | Owner and finance decisions |

---

## 7. Recommended order of work before production

1. Owner decisions:
   - mandatory Platform Admin 2FA;
   - commercial policies (§5.1-6).
2. Staging environment: HTTPS wildcard, production `.env`, restricted DB user, mail capture, trusted proxies. Run the M5 browser steps there.
3. Backups (central plus every tenant database), with a tested restore.
4. Monitoring, error reporting and alerting.
5. A written deploy and rollback runbook, including `tenants:migrate` failure handling.
6. Then, in parallel with FCL Operations: queued provisioning, a CSP with nonces, platform roles.

---

## 8. Deep review and Milestone 5.1 (2026-10-19 and 2026-10-20)

A deep code review of Milestones 1–5 ([report](FCL_SaaS_MVP_Review_Report_2026-10-19.md)) found seven defects, each with a runtime proof, plus lower-priority notes. Milestone 5.1 ([details](MILESTONE_05_1_SECURITY_BILLING_HARDENING.md)) fixed all seven and the medium notes. Every fix has a permanent regression test.

| Finding | Severity | Status after 5.1 |
|---|---|---|
| T1: tenant resolved from a forged host (password reset poisoning) | High | Fixed |
| U1: `tenants:sync-authorization` re-promoted a demoted founder | High | Fixed |
| B1: users added beyond the seats of a confirmed next term | High (revenue) | Fixed |
| S1: 2FA lockout bypassed by a fresh password login | Medium | Fixed |
| U4: a password change did not end the user's other sessions | Medium | Fixed |
| B2: an approved increase did not cancel a scheduled reduction | Medium | Fixed |
| S2: ending an impersonation rotated the account's remember-me token | Low | Fixed |
| Concurrent demotion, deactivation or self-deletion of the last Tenant Admin | Medium | Fixed (the existing lock did not work under REPEATABLE READ) |
| Provisioning: no atomic claim, tenants stuck in "provisioning", creation not audited | Medium | Fixed |
| Confirmation not bound to the previewed prices | Medium | Fixed |
| Seat requests after an early renewal | Low–Medium | Fixed |
| Lock order between seat approval and renewal (possible deadlock) | Low | Fixed |
| Payment submission token reused on another charge | Low | Fixed |
| The same subdomain registered twice at once (server error) | Low | Fixed |
| Forgot-password reveals accounts and has no per-IP limit | Low–Medium | Fixed |

Still open (low, see the M5.1 document §8):
- role edits through a shared role (`RolePolicy`);
- the support hand-over is not bound to the IP that started it;
- Livewire `__dispatch` and `updating*` hooks run before the impersonation listener (no current exposure);
- `regenerateRecoveryCodes` accepts a code from an unconfirmed secret;
- ~~the profile email is not lowercased~~ (fixed in M5.2);
- an unclear error when the Employee role holds a permission the creator lacks;
- audit records are append-only at the model level only (M5.2 extended this to the query builder of every append-only model; raw SQL remains outside).

## 9. Second deep review and Milestone 5.2 (2026-10-23)

A second deep review of the whole repository (four independent reviewers, every finding re-checked in code, six proven with temporary tests) found **no Critical or High issue**; tenant isolation held in every path checked. It found 9 Medium and about 15 Low defects. Milestone 5.2 ([details](MILESTONE_05_2_REVIEW_HARDENING.md)) fixed them, each with a regression test run once without its fix.

| Finding | Severity | Status after 5.2 |
|---|---|---|
| Account takeover through an employee email change and password reset | Medium | Fixed |
| Profile email change without the current password; unlimited current-password guesses | Medium | Fixed |
| Support could hide page views from the access history (`X-Livewire` header) | Medium | Fixed |
| Deleting a user's sessions logged out the Tenant Admin viewing as them | Medium | Fixed |
| Renewal from a stale form dropped seats bought after the preview | Medium | Fixed |
| Seat-increase estimate omitted a confirmed next term | Medium | Fixed |
| A retired permission locked Tenant Admins out of roles and users | Medium (latent) | Fixed |
| Support's 2FA check passable after enrolling with a stolen password | Medium | Fixed (2FA mandatory for Platform Admins) |
| Allowlisted pagination calls could run update hooks while impersonating | Medium (latent) | Fixed |
| Login 500 on array email, account enumeration by timing, per-account platform throttle, reset flag | Low | Fixed |
| 2FA changes left other platform browsers and support sessions alive | Low | Fixed |
| Views recorded before authorization; FCL naming gaps; support notice outside `finally` | Low | Fixed |
| Stale reduction approval, reduction marked applied wrongly, payment date and token race, unknown plan in preview | Low | Fixed |
| Custom role renamed to a reserved name; actor privileges not re-read after the lock; default branch lookup | Low | Fixed |
| Append-only gaps (`truncate`, `updateOrInsert`, bulk updates on platform/billing/event models, forged tenant audit records), redaction gaps, `subject_type` length | Low | Fixed |

Documented only (M5.2 §8): raw SQL on append-only tables, the `__Host-` cookie prefix for production, reset-link mail timing, tenant-unbound Livewire snapshots, lazy components, and the M4 renewal seat-count race.
