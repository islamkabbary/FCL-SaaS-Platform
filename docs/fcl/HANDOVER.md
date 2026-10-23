# FCL Handover — Resume Point

**Updated:** 2026-10-23 · **Branch:** `main` · **State:** FCL M1-A1 to A3 pushed; SaaS Milestone 5.2 (review hardening) committed locally. Run `git log origin/main..main` to see what is not yet pushed.

Use this page to resume FCL work on another computer or in a new Claude Code session. It is a pointer, not a replacement: the details live in the documents linked below.

## 1. Where the project is

| Phase | State |
|---|---|
| SaaS MVP (Milestones 1–5.1) | Done and committed. Owner browser verification of M5 and M5.1 is **still outstanding** |
| **SaaS M5.2** (review hardening, 2026-10-23) | **Done**, committed locally: every finding of the second deep review fixed; Platform Admin 2FA now mandatory. See [MILESTONE_05_2_REVIEW_HARDENING.md](../MILESTONE_05_2_REVIEW_HARDENING.md) |
| FCL Phase 0 (research integration, decisions, plan) | Done, commit `9a5edbb` |
| **FCL M1-A1 to A3** (permissions, impersonation denial, tenant audit) | **Done**, committed 2026-10-22 |
| FCL M1-A4 to A8, M1-B, M1-C | Not started |

## 2. What M1-A1 to A3 delivered

- **A1 — Permissions.** Nine new cases in `App\Enums\TenantPermission`:
  - `audit.view`
  - `fcl.jobs.view`, `fcl.jobs.view-all-branches`, `fcl.jobs.create`, `fcl.jobs.update`, `fcl.jobs.cancel`
  - `fcl.aci.correct`
  - `fcl.parties.view`, `fcl.parties.manage`

  `tenants:sync-authorization` gives them to Tenant Admin only. The Employee role and custom roles are never changed by the sync.
- **A2 — Impersonation denial (BD-SEC04).** During support access and employee view, FCL is refused in every layer, reads included:
  - HTTP: route name `fcl.*` or middleware `impersonation.deny`;
  - Livewire: refused before mount and before hydrate, for names `pages::fcl.*` / `fcl.*` or the marker `App\Impersonation\DeniedDuringImpersonation`;
  - Gate: generic `view`/`viewAny` refused on `App\Models\Fcl\*` or marked resources.

  The conventions for new FCL code are in [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) §4.1.
- **A3 — Tenant audit (BD-AUD01).** `App\Models\TenantAuditLog`, table `tenant_audit_logs`, tenant DB only.
  - Writes go through `TenantAuditLog::record()` and must happen inside the change's transaction.
  - The effective and original (impersonating) actor are both recorded, plus a correlation id; secrets are redacted.
  - Records are append-only: model events and `AppendOnlyBuilder`.
  - Reading needs `audit.view` and is never allowed during impersonation.
  - There is no audit UI yet. Details: [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) §4.2.

## 3. Verification (2026-10-22)

| Check | Result |
|---|---|
| `composer test` | Pint passed · PHPStan level 7, 0 errors · **Pest 478 tests: 477 passed, 1 skipped** (the tenant 2FA starter test, feature disabled, as before), 2,096 assertions |
| New tests | 51 cases from 28 tests, with datasets:<br>- `tests/Feature/Fcl/FclPermissionsTest.php` (6)<br>- `tests/Feature/Fcl/FclImpersonationTest.php` (21)<br>- `tests/Feature/Tenancy/TenantAuditLogTest.php` (24) |
| Fix verification | Each protection was removed in turn and its tests then failed:<br>- Livewire mount and hydrate checks<br>- Gate resource check<br>- route check<br>- append-only builder<br>- policy impersonation clause<br>- transaction requirement |
| Real server / browser | **Not done** for M1-A, and still outstanding for SaaS M5 (doc §18) and M5.1 (doc §9) |

## 4. Setting up on another computer

1. Clone, then follow the root `README.md` for setup.
2. Then run:
   - `php artisan migrate`
   - `php artisan tenants:migrate` (creates `tenant_audit_logs` and, since M5.2, widens its `subject_type`)
   - `php artisan tenants:sync-authorization` (adds the new permissions; since M5.2 it also deletes permissions no longer in the catalog)
   - `.env` needs an `APP_KEY` (`php artisan key:generate` after adding an empty `APP_KEY=` line); every Platform Admin must set up 2FA at their next login (`PLATFORM_REQUIRE_TWO_FACTOR`)
3. Tests use only `fcl_central_testing` and `fcl_test_tenant_*`; `TestCase` refuses anything else. Run `composer test`.
4. Before any commit, check that the git identity is exactly `Islam Kabbary <islamkabbary@gmail.com>`. The local git config on the previous machine had the name in lowercase.

## 5. Open decisions

Full list: [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md).

| Needed for | Questions |
|---|---|
| Next batch (A5–A7) | **Q-D03**: are parties shared across all branches of a company (provisional yes)? Confirmation of the nine permission names before features depend on them |
| M1-B job creation | Q-P01 Pre-Alert required fields · Q-P05 job numbering · Q-P08 branch on creation · Q-A02 which ACID level is required · Q-A03 duplicate ACID across jobs · Q-A06 ACID correction · Q-D01 party roles |
| M1-C | Q-P04 assignment · Q-P07 role seeding · Q-BR01 visibility scopes · Q-BR02 branch management |
| Strict ACID validation | Q-A01: the official ACID format (19 vs 16 digits) needs a source |

## 6. Next recommended batch (not started)

**M1-A5 to A7:**
- the ISO 6346 container-number validator and a **lenient** ACID normaliser;
- party master data (tenant-wide, audited create/update, an "Operations" sidebar group, following the §4.1 conventions);
- FCL test helpers.

Add a branch-scope primitive (A4) only when the first branch-scoped resource needs it. Do **not** start M1-B until the P1 questions above are answered.

## 7. Instructions for the next Claude Code session

1. Read the root `CLAUDE.md` (commit identity, no AI attribution, no push without authorization), then [README.md](README.md) and this page.
2. Run `git status` and `git log origin/main..main`. Do not assume this page is current; the code and git history are authoritative (BD-G02).
3. Owner decisions in [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md) override the research. PROPOSED items and open questions are not approved.
4. Keep every FCL route, component and model inside the impersonation-denial conventions. Write every FCL change and its `TenantAuditLog::record()` in one transaction.
5. Never edit `docs/fcl/research/`. Do not enable Livewire uploads (BD-SEC03). Do not cache tenant data (the cache is central).
6. Stay inside the batch the owner approved, and update the progress log in [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) and this page when a batch ends.
