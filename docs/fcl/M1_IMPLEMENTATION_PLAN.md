# FCL M1 Implementation Plan — Core Import Job Workspace

**Status:** in progress. **M1-A1 to M1-A3 implemented and committed** (2026-10-22); everything else is still a proposal. · **Created:** 2026-10-22 (Phase 0) · **Baseline:** commit `bcd7c38`

## Progress log

| Date | Step | State | Notes |
|---|---|---|---|
| 2026-10-22 | P0 decisions | Done | Owner approved Q-X01 → BD-T13, Q-S03 → BD-AUD01, PR-08 → BD-SEC04 (stricter: deny FCL reads in every layer, not by naming alone) |
| 2026-10-22 | **A1** FCL permission vocabulary | **Implemented** | 9 new cases in `App\Enums\TenantPermission`: `audit.view`, `fcl.jobs.view`, `fcl.jobs.view-all-branches`, `fcl.jobs.create`, `fcl.jobs.update`, `fcl.jobs.cancel`, `fcl.aci.correct`, `fcl.parties.view`, `fcl.parties.manage`. Synced to Tenant Admin only; existing tenants need `tenants:sync-authorization`. Names may still be adjusted before features use them |
| 2026-10-22 | **A2** Impersonation denial | **Implemented** | `RestrictionPolicy` + `ImpersonationServiceProvider` + new `DenyDuringImpersonation` middleware (alias `impersonation.deny`), marker `App\Impersonation\DeniedDuringImpersonation`. See §4.1 |
| 2026-10-22 | **A3** Tenant audit | **Implemented** | `App\Models\TenantAuditLog`, tenant migration `tenant_audit_logs`, `AppendOnlyBuilder`, `TenantAuditLogPolicy`. Existing tenants need `tenants:migrate`. See §4.2 |
| — | A4 branch scope primitive | Not started | Not needed by A1–A3; build with the first branch-scoped resource (parties are tenant-wide, so likely M1-B) |
| — | A5 validators, A6 parties, A7 test support, A8 milestone doc | Not started | Next batch |
| 2026-10-22 | Verification | See the batch report | Focused tests, protection-removal checks, full `composer test` |

M1 is split into three steps:
- **M1-A** builds foundations that depend on no operational question.
- **M1-B** delivers job creation.
- **M1-C** completes the workspace.

Each step is shippable on its own, with its own tests and commits, so development can start now without waiting for every business answer.

Read first:
- [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §4–5: the existing architecture and constraints;
- [DOMAIN_MODEL.md](DOMAIN_MODEL.md): the invariants;
- [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md) §8: the proposals PR-xx each step relies on.

---

## 1. Goal and non-goals

**Goal.** An authorized Documentation employee of a tenant can do the following:
- open an FCL Import Job from a complete Pre-Alert, with at least one ACID;
- record parties, MBL/HBLs and containers with their relationships, and ETA changes with history.

Colleagues with the right branch scope can then continue the same job. Every write is audited in the tenant DB, and nothing is visible to another tenant, to unauthorized branches or to impersonated sessions (writes).

**Not in M1:**
- document uploads or document metadata (M2);
- customs, release, delivery, charges or booking;
- REST API;
- NAFEZA integration of any kind;
- carrier tracking;
- branch transfer;
- draft jobs or an intake staging area;
- Arabic UI;
- new SaaS features.

---

## 2. Prerequisites and parallel gates

| Item | Needed for | Status |
|---|---|---|
| Owner browser verification of SaaS M5 §18 and M5.1 §9 | Confidence in impersonation and session behaviour in the browser | Outstanding; runs **in parallel** with M1-A |
| Q-X01: FCL code layout | M1-A | Provisional: existing feature-subfolder convention (PR-10) |
| Q-S03: tenant audit design | M1-A | Provisional: tenant-DB append-only audit (PR-09) |
| Q-S01: FCL reads during impersonation | M1-A (ability naming) | Provisional: FCL-specific ability names, so FCL reads are denied (PR-08) |
| Clean working tree; full suite green before starting | All | Verify at the start of M1-A (`composer test`) |

---

## 3. Conventions to follow (verified in code)

| Topic | Convention | Example in repo |
|---|---|---|
| Business logic | Action class with `handle()` that authorizes with `Gate::forUser($actor)->authorize(...)` and runs in a transaction | `app/Actions/Employees/CreateEmployee.php` |
| Permissions | Enum cases in `App\Enums\TenantPermission`, then `php artisan tenants:sync-authorization` | `employees.view` |
| Policies | `before()` denies non-tenant identities; checks with `hasPermissionTo` | `app/Policies/UserPolicy.php` |
| Pages | Livewire 4 single-file component `resources/views/pages/<area>/⚡<name>.blade.php`, `Route::livewire(...)` inside the `subscription.active` group with `can:` middleware; `$this->authorize()` in `mount()` **and** every action method | `resources/views/pages/employees/⚡index.blade.php` |
| UI | Flux UI components, `WithPagination`, `#[Url]` filters, `#[Computed]` queries | same |
| Tenant migrations | `database/migrations/tenant/` | `..._create_branches_table.php` |
| Append-only records | `App\Models\Concerns\IsAppendOnly` | impersonation events |
| Money (later) | `brick/math`, `App\Billing\Money` style; no floats | `app/Billing/Money.php` |
| Tests | Pest feature tests using `actingInTenant()`, `userWithPermissions()`, `livewireRequest()`, `startSupportSession()`, `startEmployeeView()`, `firstQueriesOfTransactions()`; test DBs only (`fcl_central_testing`, `fcl_test_tenant_*`) | `tests/Pest.php` |
| Skills | Activate `laravel-best-practices`, `livewire-development`, `fluxui-development`, `testing-best-practices` before coding | `.claude/skills/` |

The Laravel Boost guidelines in `AGENTS.md` apply: `search-docs` before version-specific APIs, no new base folders, no dependency changes without approval.

---

## 4. M1-A — Foundations (can start immediately after P0 confirmation)

**Purpose.** Build the reusable FCL security and data plumbing, without any freight decision that is still open.

| # | Deliverable | Details | Depends on |
|---|---|---|---|
| A1 | FCL permission vocabulary | Add FCL cases to `TenantPermission`, for example `fcl.jobs.view`, `fcl.jobs.view_all_branches`, `fcl.jobs.create`, `fcl.jobs.update`, `fcl.jobs.cancel`, `fcl.aci.correct`, `fcl.parties.view`, `fcl.parties.manage`. Labels for the role editor. The sync gives them to Tenant Admin only; Employee gets none (BD-SEC02) | PR-08; final names reviewed by owner |
| A2 | Impersonation stance | **Do not** add FCL abilities, routes, components or methods to `RestrictionPolicy`. Add tests proving that FCL writes **and** reads are refused during support access and employee view (BD-SEC01, Q-S01) | — |
| A3 | Tenant audit | New tenant migration and append-only model for FCL audit entries, written explicitly by actions. Fields:<br>- actor user id and actor email snapshot<br>- original actor during impersonation, if writes are ever allowed<br>- action enum<br>- subject type and id<br>- the job id, for the timeline<br>- old/new values (JSON)<br>- reason<br>- created_at<br><br>Never store passwords, tokens or session ids (R1 L997–1010) | PR-09 / Q-S03 |
| A4 | Branch scope primitive | One place that answers "may this user see/act on a job of branch X", plus one query scope for lists: own branch via `primary_branch_id`, or all branches with `fcl.jobs.view_all_branches`. Unit and feature tested with seeded extra branches (only `MAIN` exists in production tenants, Q-BR02) | PR-13 |
| A5 | Identifier validators | Pure, unit-tested classes:<br>- ISO 6346 container number: normalisation, owner code, serial, check digit (R1 L104)<br>- ACID normaliser plus a **lenient** format check: string, digits only, warning on an unexpected length; no hard 16/19 rule (BD-A02, Q-A01)<br><br>Neither validator ever claims external verification (BD-A03) | — |
| A6 | Parties (master data) | Tenant migration `parties`: legal name, optional tax/registration number, optional country code, notes, active flag, timestamps, created_by. Actions `CreateParty` / `UpdateParty` (audited) and a list/create/edit page under a new "Operations" sidebar group. No ACID requirement (BD-P09). Soft duplicate warning on name/tax number | Q-D03 provisional |
| A7 | Test support | FCL factories/helpers in `tests/Pest.php` style, for example a helper to seed a second branch and a user in it | — |
| A8 | Documentation | Start `docs/FCL_M1_IMPORT_JOB_WORKSPACE.md` (milestone doc); update [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md) with any confirmed P0 answers | — |

**M1-A tests (minimum):**
- Permissions exist after sync. Tenant Admin holds them; Employee does not.
- Parties pages:
  - 403 without `fcl.parties.view`;
  - create refused without `fcl.parties.manage`, through the action **and** through a real `livewireRequest`.
- A party created in tenant A is invisible in tenant B. Also test with the **same numeric id** in both tenants.
- Support session and employee view:
  - parties pages return 403 (reads denied, PR-08);
  - create is refused at the HTTP, Livewire and Gate layers.
- Expired subscription: parties pages are blocked like other operational pages.
- Audit:
  - entries are written for create and update with old/new values;
  - updating or deleting an entry throws.
- No FCL table exists on the central connection (schema assertion).
- ISO 6346 validator: valid, invalid and edge cases.
- ACID normaliser: 16- and 19-digit values both accepted, non-digits rejected, whitespace normalised.

### 4.1 As built: impersonation denial (A2)

FCL access is refused during support access and employee view in every layer, **reads included**. A refusal returns 403 and is recorded in the session's event trail.

| Layer | Rule | Recognised by |
|---|---|---|
| HTTP (`EnforceImpersonation` → `RestrictionPolicy::allowsRequest`) | Any method, including GET | Route name starting `fcl.`, or the `impersonation.deny` middleware on the route |
| HTTP (route middleware `impersonation.deny`) | Same, independently | The middleware itself |
| Livewire (`before('mount')` and `before('hydrate')`) | The component is never mounted, re-rendered (`$refresh`), updated or called. The listener runs before the component's own boot, mount, hydrate and `updating*` hooks | Name starting `pages::fcl.` or `fcl.`, or `implements DeniedDuringImpersonation` |
| Gate (`Gate::before`) | FCL permission abilities are not allowlisted. Generic `view`/`viewAny` are refused when the resource is FCL | Class in `App\Models\Fcl\`, or `implements DeniedDuringImpersonation` |
| Policy | `TenantAuditLogPolicy` refuses the impersonated user itself | — |

**Conventions for every FCL feature:**
- route names start with `fcl.`, inside a group that also has `impersonation.deny`;
- page components live under `resources/views/pages/fcl/` (`pages::fcl.*`);
- models live in `App\Models\Fcl\`.

Each of these is recognised on its own, so one forgotten convention does not open access. Allowing any FCL read during support access needs an approved policy (BD-SEC04).

**Added by SaaS Milestone 5.2 (2026-10-23)** ([doc](../MILESTONE_05_2_REVIEW_HARDENING.md)):
- `pages::fcl` (a page file named `⚡fcl`) and `fcl.` components in any namespace are denied as well.
- FCL resources passed to `view`/`viewAny` inside arrays or collections are refused.
- **Do not use lazy components (`#[Lazy]`, `lazy`) in tenant pages.** Livewire mounts them before the impersonation listener can refuse the call. A test fails if one is added.
- **Pagination:** only the default `page` paginator works during impersonation. Do not give FCL tables custom paginator names that are expected to work there.
- **Renaming permissions:** list the old name in `TenantPermission::renamed()` in the same release. Otherwise `tenants:sync-authorization` deletes the old permission and its grants.
- **Audit:** `TenantAuditLog` accepts records only through `record()`. Pass arrays, Collections or JsonSerializable values, never JSON strings, so that redaction applies.

### 4.2 As built: tenant audit (A3)

**Writing records.** Use `TenantAuditLog::record($action, $actor, $subject, $old, $new, $reason, $metadata)`.
- `$action` is a string-backed enum of the calling feature, for example a future `App\Enums\Fcl\FclAuditAction`. No action enum is defined yet, because no audited FCL action exists.
- `$actor` is a `User`, or `null` for the system.
- **The call must be inside the database transaction of the change.** Outside one it throws, so a change and its record commit or roll back together.

**What a record stores:**
- action, subject type and id;
- the effective actor: id, name and email snapshot;
- during an impersonation, the kind, the session id and the original actor (Platform Admin or Tenant Admin: type, id, name, email);
- a per-HTTP-request correlation id (none outside HTTP);
- old values, new values and metadata, each run through `redact()`, which hides password, secret, token, remember, credential, API key, signature, cookie and session keys at any depth;
- the reason and `created_at`.

**Guards:**
- tenant context only;
- the subject must not be a central model;
- the action must be string-backed.

**Immutability:**
- `IsAppendOnly` covers changes to loaded records;
- `AppendOnlyBuilder` (`#[UseEloquentBuilder]`) refuses mass `update`, `upsert`, `touch`, `increment`/`decrement` (also the `Each` variants), `delete` and `forceDelete`;
- `DB::table()` / `toBase()` and direct database access are not covered (documented limitation).

**Reading.**
- Requires `audit.view` and an active user.
- Never during an impersonation.
- No create, update or delete abilities exist.
- **No audit UI was built** in this batch.

**Location.** The tenant database only. Tests assert that the central database has no `tenant_audit_logs` table and that `platform_audit_logs` is not written.

**M1-A acceptance:**
- `composer test` passes: Pint, PHPStan level 7, Pest.
- No new package.
- No change to SaaS behaviour, except the new permission rows and the sidebar group.

---

## 5. M1-B — Import Job creation (after the P1 questions)

**Purpose.** Create the Import Job with its mandatory ACID, parties and branch, and let users read it.

**Blocking questions:**

| Question | Topic | Provisional |
|---|---|---|
| Q-P01 | required fields | PR-03 |
| Q-P05 | numbering | PR-06 |
| Q-P08 | branch on create | — |
| Q-A02 | which ACID level | — |
| Q-A03 | cross-job duplicate ACID | PR-05 |
| Q-A06 | ACID correction | — |
| Q-D01 | party roles | PR-07 |

Each has a provisional answer. The owner may confirm all of them in one reply. **Do not start M1-B migrations until they are confirmed or explicitly accepted as provisional**, because they shape the schema.

| # | Deliverable | Details |
|---|---|---|
| B1 | Migrations | Tables:<br>- `import_jobs`: job number (unique), `branch_id` FK, source channel, lifecycle (PR-01: open / operationally complete / closed / cancelled; **no draft**), `created_by` (immutable), optional POL/POD (UN/LOCODE strings), vessel, voyage, current ETA, ATA, `operationally_completed_at`, `financially_closed_at`, timestamps, `lock_version`<br>- `aci_records`: job FK, ACID string, optional BL FK (nullable until B-ext/C1), notes, created_by; unique (job, ACID)<br>- `job_parties`: job FK, party FK, role enum; unique (job, party, role)<br><br>No ACID column on `import_jobs` |
| B2 | `CreateImportJob` action | One transaction:<br>1. authorize create + branch scope;<br>2. validate the PR-03 minimum (≥ 1 ACID, branch, source channel, customer);<br>3. normalise ACIDs;<br>4. detect duplicates across jobs (ACID, MBL later) and require an explicit confirmation flag;<br>5. allocate the job number under a lock;<br>6. insert job, ACI records and job parties;<br>7. write the audit entry and a "job created" timeline entry.<br><br>Fails closed if no ACID. The Livewire page and any future API call this action only |
| B3 | ACI record actions | `AddAciRecord`. `CorrectAciRecord`, gated by `fcl.aci.correct`, with a reason and old/new values in the audit. `RemoveAciRecord` is refused when it is the last one (invariant I-J1) |
| B4 | Job parties actions | Add/remove a party in a role (customer required per PR-03; origin agent optional) |
| B5 | Create page | Livewire page: source channel, customer and other roles (searching existing parties, with a quick-create that reuses `CreateParty`), ACID(s) with a lenient-format warning, optional route/vessel/ETA. Clear wording that the ACID is **not verified** against NAFEZA |
| B6 | Job list (inbox, first version) | Branch-scoped list: job number, customer, source, ACIDs, POD, ETA, branch, created by. Filters: "my branch", "created by me". Pagination and search |
| B7 | Job workspace: Overview | Read view: header, parties, ACI records, a readiness placeholder only for implemented domains (no fake "customs: not started" badges for unbuilt features) |
| B8 | Docs | Update the milestone doc and the decision register |

**M1-B tests (minimum):**
- **Creation invariant:**
  - creation without an ACID is refused (validation and action);
  - removing the last ACID is refused;
  - a creation that fails after the ACID insert leaves no job (rollback).
- **Numbering:**
  - unique and sequential;
  - concurrency proven with a second DB session or the `firstQueriesOfTransactions()` lock-order technique, as in M5.1.
- **Duplicates:**
  - the same ACID on a second job needs confirmation;
  - the same ACID twice on one job is refused.
- **Branch:**
  - a user in branch X without the all-branches permission cannot see, open or update a job of branch Y, through the list, the URL and a real Livewire request;
  - with `fcl.jobs.view_all_branches` they can.
- **Tenant isolation:**
  - a job id from tenant A requested on tenant B returns 404;
  - identical numeric ids in both tenants do not leak.
- **Impersonation:** creating a job and correcting an ACID are refused under support access and employee view; reads are denied (PR-08).
- **Subscription:** expired → blocked.
- **Audit:** create, ACID correction (with reason) and party changes are recorded with actor and old/new values.
- **Creator:** `created_by` is set and cannot be changed through any action.
- **ACID format:** 16- and 19-digit ACIDs are both accepted (no hard rule until Q-A01).

**M1-B acceptance:**
- A Documentation-role user (a role composed by the Tenant Admin from FCL permissions) creates a job for a direct-line shipment (no origin agent) and for an origin-agent shipment.
- Both are visible only within the allowed branch scope.
- `composer test` is green.

---

## 6. M1-C — Workspace completion (after the P2 questions for the parts that need them)

| # | Deliverable | Depends on |
|---|---|---|
| C1 | Bills of lading | `bills_of_lading`: job FK, type (master/house), number, optional parent BL (an HBL's parent must be an MBL on the same job), optional carrier party, issue date. Link ACI records to a BL optionally. Duplicate MBL detection with confirmation (Q-B03 provisional). Can start without P2 answers |
| C2 | Containers | `containers` (job FK, number unique **within** the job, optional ISO size/type and seal) and `bl_containers` (many-to-many, same-job guard). ISO 6346 validation with a recorded override (Q-C02 provisional). Can start without P2 answers |
| C3 | ETA history and arrival facts | `ChangeEta` action: updates the current ETA and appends an immutable timeline entry (old → new, actor, optional reason). ATA recorded separately. Can start without P2 answers |
| C4 | Timeline tab | Job timeline from the tenant audit/events, newest first, with actor names |
| C5 | Job lifecycle | Cancel (with reason; `fcl.jobs.cancel`). Operational completion is **not** built yet, because its criteria are Q-W07 |
| C6 | Visibility scopes | Final branch scoping per Q-BR01; extra-branch grants only if confirmed | **Q-BR01** |
| C7 | Assignment | Only if Q-P04 is answered. Otherwise M1 ships with `created_by` only and assignment moves to M2 | **Q-P04** |
| C8 | Role guidance | Document the suggested permission sets for Documentation / Operations / Supervisor roles. Seed roles only if Q-P07 says so | **Q-P07** |
| C9 | Inbox filters | Arriving this week (ETA), my branch, created by me, cancelled hidden by default | — |
| C10 | Real-server and browser verification | Two tenants on the local server with real cookies and Livewire updates (M5-style curl run), then the owner's browser steps. Use only the testing DBs or explicitly approved local tenants; port per the local smoke-server rule | — |
| C11 | Milestone documentation | `docs/FCL_M1_IMPORT_JOB_WORKSPACE.md` completed, committed last | — |

**M1-C tests (minimum):**
- **BL structure:**
  - an HBL parent on another job is refused;
  - an HBL parented to an HBL is refused;
  - an MBL with 3 HBLs works.
- **Containers:**
  - a container linked to two BLs of the same job works;
  - a link to a BL of another job is refused;
  - the same container number on two jobs is allowed;
  - the same container number twice on one job is refused.
- **ISO 6346:** an invalid check digit is refused without an override; the override is recorded in the audit.
- **ETA history:** history is preserved across three changes; ATA is independent of ETA.
- **Cancel:** needs a permission and a reason; the job stays readable, is hidden from the default list and is audited.
- **Security on every new action:**
  - branch scope;
  - tenant isolation;
  - impersonation refusal;
  - expired subscription;
  - Livewire action-level authorization after the initial render, e.g. a permission revoked between render and action.

---

## 7. Risks and mitigations

| Risk | Effect | Mitigation |
|---|---|---|
| The impersonation allowlist allows `viewAny`/`view` and all GETs | Support staff could read FCL data if FCL used generic ability names | FCL-specific ability names (PR-08); tests in A2 |
| No tenant audit exists | FCL writes unaudited, or audit written to central | A3 builds a tenant-DB audit first |
| Only `MAIN` branch in real tenants | Branch-scope bugs invisible in manual testing | Tests seed extra branches; branch UI is a separate decision (Q-BR02) |
| Central cache | Tenant data leaking through the cache | No caching of FCL data in M1 |
| Job-number race under concurrent creation | Duplicate numbers or deadlocks | Lock plus a unique index; concurrency test using the M5.1 techniques |
| Schema decided before P1 answers | Costly migrations later | M1-B waits for confirmation; M1-A has no freight schema except parties |
| ACID format guessed | Valid ACIDs rejected | Lenient validator until Q-A01 (BD-A02) |
| Livewire `#[On]` / `updating*` hooks run before the impersonation listener (M5.1 §8) | A write in such a hook would bypass impersonation checks | No writes in `updating*` hooks or `#[On]` handlers in FCL components; review any that are added |
| Composer can rewrite `CLAUDE.md` through Boost on `composer require` | Unrelated diffs | No dependency changes in M1; check `git diff CLAUDE.md` before committing |
| Scratch tests committed | Repository pollution | The global rule: scratch tests in git-ignored paths, stage explicit paths only |
| Browser verification of M5/M5.1 still outstanding | Impersonation behaviour unproven in a real browser | Owner completes it in parallel; M1-C repeats impersonation checks on the real server |

---

## 8. M1 acceptance criteria (whole milestone)

1. A tenant user with the right permissions can open an Import Job **only** with at least one ACID; the job records its creator and primary branch.
2. Origin-agent and direct-shipping-line jobs both work; the origin agent, booking and HBL are optional.
3. MBL/HBL hierarchy, several ACI records and BL ↔ container many-to-many are represented and validated.
4. ETA changes keep an immutable history; ATA is separate.
5. Every write is in the tenant audit with actor, time and old/new values; the timeline shows it.
6. Branch scope is enforced in lists, pages, Livewire actions and domain actions.
7. Tenant B cannot discover any record of tenant A (same-id tests).
8. No FCL data or table exists in `fcl_central`.
9. Support access and employee view cannot write FCL data, and cannot read it unless the owner approves otherwise.
10. Expired or suspended tenants cannot reach FCL pages.
11. Nothing claims NAFEZA verification or integration.
12. `composer test` is green (Pint, PHPStan level 7, Pest), and each regression test was run once without its fix.
13. Real-server run and owner browser verification are completed and documented.
14. A real anonymised shipment from the target company can be entered end to end (R2 L1029). If no real file is available, the milestone document says so explicitly.

---

## 9. What can start now, and what is blocked

| Work | Can start | Blocked by |
|---|---|---|
| M1-A1 to A3 | **Done** (2026-10-22) | — |
| M1-A4 to A8 | **Yes** | A6 parties: Q-D03 provisional (tenant-wide parties) |
| M1-B migrations and actions | No | Q-P01, Q-P05, Q-P08, Q-A02, Q-A03, Q-A06, Q-D01 (each has a provisional answer to accept) |
| M1-C1 to C5, C9 | After M1-B | — |
| M1-C6 / C7 / C8 | After M1-B | Q-BR01 / Q-P04 / Q-P07 |
| Strict ACID format validation | No | Q-A01 (official source) |
| Documents, customs, delivery, finance | No | Later milestones |
