# FCL M1 — Core Import Job Workspace (milestone report)

**Status:** in progress. This report starts with M1-A (foundations) and grows with each batch. It is completed at the end of M1 (step C11 of the [plan](fcl/M1_IMPLEMENTATION_PLAN.md)).

| Batch | Content | State |
|---|---|---|
| M1-A1 to A3 | FCL permissions, impersonation denial, tenant audit | Done 2026-10-22, pushed |
| **M1-A5 to A8** | **Identifier validators, parties, FCL test helpers, this report** | **Done 2026-10-30, committed locally, not pushed** |
| M1-A4 | Branch scope primitive | Not started: built with the first branch-scoped resource (M1-B) |
| M1-B, M1-C | Import Job creation, then the full workspace | Not started: M1-B waits for the owner's answers (plan §5) |

> **What "verified" means.** Behaviour below is covered by automated Pest tests on the test databases (`fcl_central_testing`, `fcl_test_tenant_*`), except where §7 says otherwise. The batch also had an independent review (§7). The protections listed in §7 were each removed once to see which tests fail. No real-server or browser run was done; the owner's browser steps are in §9. Visual details, such as the note truncation, have no automated test.

---

## 1. Owner decisions used by this batch (2026-10-23)

Recorded in [BUSINESS_DECISIONS.md](fcl/BUSINESS_DECISIONS.md) §4.

| ID | Question | Decision |
|---|---|---|
| BD-D01 | Are parties shared across branches (Q-D02)? | **Yes.** One company-wide list. Branch scope applies to jobs, not to parties |
| BD-D02 | Duplicate parties (Q-D03) | **A warning, then save.** The user sees the similar parties and may confirm. No database uniqueness rule |
| BD-D03 | Party fields of the first version | Legal name (required), tax or registration number, country, notes, active flag |
| BD-D04 | The nine FCL permission names of M1-A1 | **Approved as they are** |

---

## 2. M1-A1 to A3 (summary)

Delivered on 2026-10-22 and described in the plan: permissions ([§ progress log](fcl/M1_IMPLEMENTATION_PLAN.md)), impersonation denial ([§4.1](fcl/M1_IMPLEMENTATION_PLAN.md#41-as-built-impersonation-denial-a2)) and tenant audit ([§4.2](fcl/M1_IMPLEMENTATION_PLAN.md#42-as-built-tenant-audit-a3)).

- **A1.** Nine permissions in `App\Enums\TenantPermission` (`audit.view`, `fcl.jobs.*`, `fcl.aci.correct`, `fcl.parties.view`, `fcl.parties.manage`), given by `tenants:sync-authorization` to the Tenant Admin role only.
- **A2.** FCL is refused during support access and employee view in every layer, reads included: route names `fcl.*` and the `impersonation.deny` middleware; Livewire components `pages::fcl.*`, `fcl.*` or marked `DeniedDuringImpersonation`, refused before mount and hydrate; the Gate, also for `view`/`viewAny` on `App\Models\Fcl\*`.
- **A3.** `App\Models\TenantAuditLog` in each tenant database: written only through `record()` inside the change's transaction, append-only, readable with `audit.view` and never during an impersonation.

Commits: `ed6a6ba`, `7b808c2`, `df43154`, `2f7b3ca`, `2692b9a`, `08d805b`. SaaS Milestone 5.2 later hardened A2 and A3 further ([MILESTONE_05_2_REVIEW_HARDENING.md](MILESTONE_05_2_REVIEW_HARDENING.md)).

---

## 3. M1-A5 — Identifier validators

Two Laravel validation rules in `app/Rules/Fcl/`, each also a set of pure static functions. **Neither claims external verification** (BD-A03): a valid number is a well-formed number, nothing more. No screen uses them yet; M1-B will use `Acid` and M1-C `ContainerNumber`.

### 3.1 `App\Rules\Fcl\ContainerNumber` (ISO 6346)

| Function | Behaviour |
|---|---|
| `normalize()` | Upper case; spaces, dashes and invisible format characters (direction marks, zero-width spaces) removed; fullwidth characters written as ASCII (`csqu 305438-3` → `CSQU3054383`) |
| `isWellFormed()` | Three letters (owner code), a category identifier (`U` freight container, `J` detachable equipment, `Z` trailer or chassis), six digits (serial), one digit (check digit) |
| `checkDigit()` | ISO 6346: letters are worth 10 to 38, skipping multiples of 11; each of the ten characters is weighted 2^position; the sum modulo 11, with a remainder of 10 written as 0. Refuses anything but four letters and six digits |
| `hasValidCheckDigit()`, `isValid()` | Well-formed and the check digit matches |
| As a rule | Refuses a malformed value, then a wrong check digit. `ContainerNumber::allowWrongCheckDigit()` accepts a wrong check digit (never a malformed number), for the deliberate, audited override planned in M1-C |

Tested against the published examples `CSQU 305438 3` and `MSKU 907032 3`, and a number whose remainder is 10.

### 3.2 `App\Rules\Fcl\Acid` (lenient)

The official ACID format is still unconfirmed (BD-A02, Q-A01), so the rule is deliberately lenient and lives in one class to be replaced later.

| Function | Behaviour |
|---|---|
| `normalize()` | Removes all whitespace and invisible format characters that come with pasted text; writes fullwidth, Arabic-Indic (`٠`–`٩`) and Extended Arabic-Indic (`۰`–`۹`) digits as ASCII digits. Leading zeros are kept: an ACID is always a string |
| `isWellFormed()` | Digits only, at least one |
| `lengthWarning()` | A message when a well-formed ACID has neither 16 nor 19 digits; `null` otherwise. A warning for the user, never an error |
| As a rule | Refuses non-strings and anything with a non-digit after normalisation (letters, hyphens, signs, decimal points). Empty values are left to `required`, as for every non-implicit Laravel rule |

---

## 4. M1-A6 — Parties

Parties are the customers, importers, agents and carriers of FCL Operations: master data of the company, shared by all its branches (BD-D01), without any ACID (BD-P09).

### 4.1 Data

Tenant migration `2026_10_23_200000_create_parties_table` (tenant databases only):

| Column | Type | Notes |
|---|---|---|
| `legal_name` | string(255) | Required |
| `name_key` | string(255), indexed | Normalised name for the duplicate check |
| `tax_number` | string(50), nullable | Stored as typed (trimmed, spaces collapsed); format not validated (DOMAIN_MODEL I-P3) |
| `tax_number_key` | string(50), nullable, indexed | Letters and digits only, fullwidth and Arabic-Indic digits as ASCII, ASCII upper case; null when empty |
| `country_code` | char(2), nullable | Two letters, upper case |
| `notes` | text, nullable | At most 2,000 characters |
| `is_active` | boolean, default true, indexed | A party is deactivated, never deleted |
| `created_by` | users, nullable, null on delete | The creating user |
| `created_at`, `updated_at` | timestamps | |

No branch column, no ACID, and deliberately **no unique constraint** (BD-D02).

### 4.2 Code

| Part | File | Notes |
|---|---|---|
| Model | `app/Models/Fcl/Party.php` | In `App\Models\Fcl`, so `RestrictionPolicy` denies it during impersonation. Fillable: the five editable attributes only. A `saving` hook computes the two keys. `similarTo()` finds likely duplicates. `#[UsePolicy(PartyPolicy::class)]` |
| Policy | `app/Policies/Fcl/PartyPolicy.php` | `viewAny`, `view`: `fcl.parties.view`. `create`, `update`: `fcl.parties.manage`. Any non-tenant identity is refused. A missing permission row denies rather than fails (`checkPermissionTo`), because `viewAny` runs on every page for the sidebar. No `delete` ability |
| Validation | `app/Actions/Fcl/ValidatePartyAttributes.php` | Used by both actions, so every caller is validated, not only the page |
| Create | `app/Actions/Fcl/CreateParty.php` | Authorize, validate, then one transaction: duplicate check, insert, `TenantAuditLog::record(fcl.party.created)` with the new values |
| Update | `app/Actions/Fcl/UpdateParty.php` | Authorize, then one transaction: lock the row, validate the given values over the current ones (attributes left out are kept), compare, duplicate check on any key that changed, save, `TenantAuditLog::record(fcl.party.updated)` with the old and new values of the changed attributes only. An unchanged save writes nothing |
| Duplicate warning | `app/Exceptions/Fcl/PossibleDuplicateParty.php` | Carries the similar parties |
| Audit actions | `app/Enums/Fcl/FclAuditAction.php` | `fcl.party.created`, `fcl.party.updated` (with labels for a future audit screen) |
| Page | `resources/views/pages/fcl/parties/⚡index.blade.php` | Component `pages::fcl.parties.index` |
| Route | `routes/tenant.php` | `GET /fcl/parties`, name `fcl.parties.index`, in a `fcl.` group with `impersonation.deny`, inside `auth`, `verified`, `password.changed` and `subscription.active`, with `can:viewAny` on `Party` |
| Menu | `resources/views/layouts/app/sidebar.blade.php` | New group **Operations** with **Parties**, shown to users who may view parties |
| Factory | `database/factories/Fcl/PartyFactory.php` | Tests only; the application creates parties through `CreateParty` |

### 4.3 Validation

| Field | Rule | Normalisation |
|---|---|---|
| Legal name | Required, text, at most 255 | Trimmed, runs of whitespace collapsed |
| Tax / registration number | Optional, text, at most 50 | Trimmed, whitespace collapsed, empty → none |
| Country | Optional, exactly two letters `A`–`Z` | Upper case (ASCII only, so `ß` never becomes `SS`). Not checked against the ISO 3166 list |
| Notes | Optional, text, at most 2,000 | Trimmed, empty → none |
| Active | Boolean | Defaults to active |

A validation error from the action is shown on the matching form field.

### 4.4 Duplicate warning

Two parties look like the same company when their **name keys** or their **tax-number keys** are equal.

- **Name key:** compatibility-decomposed (fullwidth letters and Arabic presentation forms become ordinary letters); lower case; diacritics and the Arabic tatweel removed; the Arabic letter variants `أ إ آ ٱ` → `ا`, `ة` → `ه`, `ى` → `ي` folded; every run of other characters becomes one space. So "ACME Trading Co." matches "acme  trading co", "Société" matches "Societe", and "شركة الأمل" matches "شَركه الامـل".
- **Tax-number key:** letters and digits only, upper case, fullwidth and Arabic-Indic digits as ASCII. So "123-456-789" matches "123 456 789" and "١٢٣٤٥٦٧٨٩".
- The comparison itself runs under the tenant column's collation (`utf8mb4_unicode_ci`), which can also treat other characters as equal. That can only add warnings.
- An empty key (a name of symbols only, a tax number of separators only) matches nothing.

Flow:
1. The action finds similar parties and throws `PossibleDuplicateParty`. Nothing is saved and nothing is audited.
2. The page lists them in a warning ("This party may already exist") and replaces Save with **Save anyway**.
3. Save anyway calls the action with `$confirmedSimilarIds`, the ids of the parties listed. The audit metadata records `confirmed_despite_similar_party_ids`.

A confirmation covers **only the parties the warning listed**. Leaving the name or tax-number field after changing it clears the warning, so the new values are checked again; a similar party added meanwhile gives a new warning; a client that sends the confirmation without having been warned is warned first. The warning list and the edited party's id are `#[Locked]`, so the browser cannot change them.

An update checks only a key that changes, so an already confirmed copy can still be edited, renamed by case or given a tax number.

### 4.5 The page

- List of parties, sorted by legal name, 15 per page (the default paginator).
- Search by legal name or tax number; "123456789" also finds "123-456-789".
- Status filter: Active (default), Inactive, All.
- **New party** and **Edit** buttons only for users who may manage parties. The active flag is edited in the edit form.
- Every action authorizes again in the backend: the `can:` check is re-applied to every Livewire update, `create`/`edit` authorize, and the actions authorize through the Gate.

---

## 5. M1-A6 — Impersonation, isolation and subscription

| Situation | Behaviour | Layer |
|---|---|---|
| Support access or employee view opens `/fcl/parties` | 403, recorded as denied (`fcl.parties.index`) | Route name and `impersonation.deny` |
| A parties page opened before the impersonation sends an update | 403, recorded as denied (`livewire.update: pages::fcl.parties.index`) | The re-applied `can:` check (the Gate refuses `App\Models\Fcl` resources). It runs first, so on this page the `hydrate` listener is not reached; that listener is tested with probe components in `FclImpersonationTest` |
| The actions are called during an impersonation | `AuthorizationException` | `Gate::before` in `ImpersonationServiceProvider` |
| The Operations menu during an impersonation | Hidden | The Gate refuses `viewAny` on `Party` |
| Another company | Never visible: separate tenant databases. Tested with the same numeric id in both companies, for listing, searching, editing and the duplicate check | Database per tenant |
| No active subscription | Redirected to `subscription-required`, like other operational pages | `subscription.active` |

**Fix in the impersonation history (SaaS behaviour).** Refusals by the allowlist, by `impersonation.deny` and by the Livewire listeners were already recorded. One gap remained: a request other than GET that `EnforceImpersonation` let through and that was refused with 403 further on, in practice a Livewire update refused by a `can:` check that Livewire re-applies. Such a request is now recorded as denied, once, and a Livewire update is named after its component. Successful GET requests are still recorded as viewed. File: `app/Http/Middleware/EnforceImpersonation.php`.

---

## 6. M1-A7 — Test support

New helpers in `tests/Pest.php`:

| Helper | Purpose |
|---|---|
| `defaultBranch()` | The current tenant's default branch |
| `extraBranch($code, $name)` | A second branch. Real companies have only their default branch so far (Q-BR02), so branch tests seed their own |
| `userInBranch($branch, $permissions)` | A user working in that branch with exactly those permissions |
| `createParty($attributes, $actor, $duplicateConfirmed)` | A party created through the audited action (by the Tenant Admin by default) |

---

## 7. Tests

| File | Cases | Covers |
|---|---|---|
| `tests/Unit/Fcl/IdentifierValidatorsTest.php` (new) | 52 | Container normalisation, check digits against published examples, valid and malformed numbers, the check-digit override; ACID normalisation (spaces, non-breaking spaces, Arabic-Indic digits, leading zeros), 16 and 19 digits without warning, other lengths with a warning, refused values; pasted invisible characters, fullwidth characters, trailing newlines; the party duplicate keys directly |
| `tests/Feature/Fcl/PartiesTest.php` (new) | 47 | Permissions on the page, the menu and both actions; creating from the page with normalisation and audit; validation on the form and in the actions; duplicate warning (Latin, Arabic, tax number), confirmation and its limits; updates audited with changed values only; deactivation and filters; search; sharing across branches; isolation with the same id in two companies; tenant tables only; subscription; immutable audit records; support access and employee view in every layer; a permission taken away while the page is open; partial updates; browser tampering with locked state; a missing permission row |
| `tests/Feature/Hardening/ImpersonationHistoryTest.php` | +1 | A Livewire update refused by the page's authorization is recorded as denied |

**Independent review.** Before the commit, five read-only reviewers examined the batch (security, domain logic, the page, tests, documents) and one skeptic tried to refute each finding: 43 findings, 36 confirmed, 7 refuted, all confirmed ones low or informational. No critical, high or medium issue was found. Fixed as a result:
- "Save anyway" now covers only the parties listed, and a partial update no longer clears other fields;
- an update checks only a key that changed;
- a missing permission row denies instead of failing on every page;
- pasted invisible characters and trailing newlines are handled;
- the warning clears when the changed field loses focus, and long notes no longer stretch the table;
- refused Livewire updates name their component;
- the document errors and the weak tests the review listed.

Not changed: the review noted that an edit form opened earlier can overwrite a colleague's later change (§8).

**Protection-removal checks.** Each protection below was removed in turn from the final code, and the listed tests then failed. The suite was green again after every restore.

| Protection removed | Tests that fail |
|---|---|
| `Gate::authorize` in both actions | The manage-permission cases, the viewer test, both impersonation tests, the revoked-permission test |
| The duplicate check in `CreateParty` | The likely-duplicate cases, both "Save anyway" tests, the confirmation-scope test |
| Validation in `CreateParty` | The form and action validation cases, the create-and-normalise test |
| The update audit action | The update-audit test, the partial-update test |
| Recording refused non-GET requests | The history test, both impersonation tests |
| Naming the refused Livewire component | The history test, both impersonation tests |
| Confirmation tied to the ids shown | The confirmation-scope test |
| Confirmation only while the warning is shown | The "Save anyway" limits test |
| Keeping omitted attributes on update | The partial-update test |
| Checking only changed keys on update | The update duplicate-rules test |
| `#[Locked]` on `similarParties`, on `editingId` | The matching browser-tampering case |
| `authorize` in `edit()` | The viewer test |
| A missing permission row denies | The not-yet-synced test |
| The empty-key guard | The symbols-only test, the update duplicate-rules test |
| Arabic letter folding | The Arabic-variants case |
| Compatibility decomposition; Arabic-Indic digits in tax keys | The direct key test (the database collation hides these two in the feature tests) |
| `impersonation.deny` on the route group | The route-recognition test (the route name still refuses on its own) |
| Format characters in `Acid::normalize` | The pasted-characters case |
| The end anchors in `Acid` and `ContainerNumber` | The trailing-newline test |

**Not removal-checked:** the `@can` on the New party and Edit buttons (the backend refuses anyway), `DB::transaction` and `lockForUpdate` in the actions. `TenantAuditLog::record()` throws outside a transaction, so a missing transaction would fail every audited test, but no concurrency test exercises the row lock. Each denial layer on its own is covered by `tests/Feature/Fcl/FclImpersonationTest.php`.

**Full suite** (`composer test`, 2026-10-30): Pint passed · PHPStan level 7, 0 errors · **Pest 685 tests: 684 passed, 1 skipped** (the tenant 2FA starter test, feature disabled, as before), 2,815 assertions. An earlier full run had one failure in an older test, `repeated wrong passwords are rate limited`: its exact countdown changed when a second passed mid-test. Time is now frozen in that test (`60a747e`).

---

## 8. Known limitations

- **The duplicate check is a warning, not a lock.** Two people creating the same company at the same moment can both succeed without a warning (BD-D02 chose no database rule).
- **Name matching is exact after normalisation.** "Acme Trading" and "Acme Trading Co." are not reported; there is no fuzzy matching and no legal-suffix removal. Merge tooling stays deferred (Q-D03).
- **Concurrent edits.** The edit form sends all its fields, so a save from a form opened earlier overwrites a colleague's later change. The audit shows both changes. Optimistic locking was not added for master data in this batch.
- **Arabic folding applies to the duplicate check only.** The list search is a plain `LIKE` on the stored name and tax number.
- **Search wildcards.** `%` and `_` typed in the search box act as wildcards, as on the Employees page.
- **Country** is any two letters; it is not checked against the ISO 3166 list. **Tax numbers** have no format rule (DOMAIN_MODEL I-P3).
- **No delete** (deactivate instead) and **no audit screen** yet; audit records are in `tenant_audit_logs`.
- **Validators unused.** A5 has no screen until M1-B and M1-C.
- **Branch scope (A4)** is not built: parties need none.
- **No real-server or browser run** in this batch (§9). The owner's browser checks of SaaS M5, M5.1 and M5.2 are still outstanding as well.

---

## 9. Deployment and manual verification

**Deployment.**
1. `php artisan tenants:migrate`: creates `parties` in every tenant database (and applies M5.2's tenant migration if not done yet).
2. `php artisan tenants:sync-authorization`, unless it already ran after M5.2. It is safe to repeat. Without it, the Operations menu stays hidden and the page gives 403.
3. Rebuild the front-end assets (`npm run build`, or `npm run dev` locally): the page uses Tailwind utilities the current build does not contain.
4. The local `.env` still needs an `APP_KEY` (`php artisan key:generate`).

**Browser steps for the owner** (on a local tenant, never production):
1. Sign in as the Tenant Admin. The sidebar shows **Operations → Parties**.
2. Create "Acme Trading Co." with tax number "123-456-789" and country "eg". The list shows it with country "EG".
3. Create "ACME trading co". A warning lists "Acme Trading Co. (123-456-789)" and the button reads **Save anyway**. Change the name to something new and leave the field: the warning disappears and the button reads **Save** again. Change it back, press Save, then Save anyway: the party is created.
4. Edit a party and untick **Active**. It leaves the default list and appears under **Inactive**.
5. Search "123456789": the party with "123-456-789" is found.
6. Give an employee a role with only "FCL: view parties". They see the list without **New party**. An employee without it sees no Operations menu, and `/fcl/parties` gives 403.
7. Start a support session from the platform. The Operations menu is hidden, `/fcl/parties` gives 403, and the support session's history shows the denial. Repeat with an employee view.
8. Optional: in the tenant database, `tenant_audit_logs` has `fcl.party.created` and `fcl.party.updated` rows with the values changed.

---

## 10. Commits (on `main`, not pushed)

| Commit | Content |
|---|---|
| `2b57162` | feat(fcl): identifier validators (A5) |
| `20db216` | fix(impersonation): record refused Livewire updates in the access history |
| `6e1d97b` | feat(fcl): company-wide parties with audited create and update (A6) |
| `cb2ea0c` | test(fcl): cover parties and add FCL test helpers (A7) |
| `60a747e` | test(impersonation): freeze time in the support password throttle test |
| The commit holding this report | docs(fcl): this report and the decision records (A8) |

The four feature and test commits were created before the last full run had been read; that run showed the failure above. They were kept as they are, with the fix in a later commit, because history is not rewritten. The final state is the verified one.

Author and committer of every commit: `Islam Kabbary <islamkabbary@gmail.com>`. No package was added or changed.
