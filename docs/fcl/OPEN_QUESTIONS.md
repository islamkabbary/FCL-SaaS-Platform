# FCL Open Questions — Decision Backlog

**Status:** living document · **Created:** 2026-10-22 (Phase 0)

An open question is **not** a requirement. A "provisional approach" is Claude's recommendation for a safe default; it is **not approved** until the owner records a decision in [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md). When a question is answered, move the answer there (with a decision ID) and mark the question **RESOLVED → BD-xxx** here; do not delete it.

## How to read the classification

| Code | Meaning |
|---|---|
| **BLOCKS `<step>`** | Must be answered before that specific implementation step (see [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) / [MVP_ROADMAP.md](MVP_ROADMAP.md)). Nothing else is blocked by it |
| **DEFER** | Safe to postpone; the M1 design leaves room for any answer |
| **OPS** | Needs validation with real Egyptian Operations / Documentation / Customs / Finance staff or real shipment files |
| **REG** | Needs regulatory or API verification from an official source |

A question can carry several codes.

## Priority summary

| Priority | Questions | Needed by |
|---|---|---|
| **P0 — before M1-A (foundations)** | ~~Q-X01, Q-S03~~ resolved 2026-10-22 | — |
| **P1 — before M1-B (job creation)** | Q-P01, Q-P05, Q-P08, Q-A02, Q-A03, Q-A06, Q-D01 (Q-S01 resolved) | first migrations of the Import Job and ACI record |
| **P2 — before M1-C (workspace, assignment, visibility)** | Q-P04, Q-P07, Q-BR01, Q-BR02 | job list scoping and assignment |
| **P3 — before a strict rule is coded** | Q-A01, Q-R01 | ACID format validator becomes strict |
| **Later milestones** | everything else | M2–M6 |

M1-A1 to A3 (2026-10-22) and A5 to A8 (2026-10-30) are implemented. A4 waits for the first branch-scoped resource and is blocked by no question.

---

## 1. Pre-Alert and Import Job creation

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-P01** | What exactly must a Pre-Alert contain (fields and documents) before a job may be created? (BD-P06) | **BLOCKS M1-B** (required-field rules only) · OPS | Enforce in code **only** what is already decided or structurally needed: an ACID (BD-P05), the primary branch, and the creator (automatic). Every other field is stored but optional at the database level. Keep "required for creation" in one validation class so the checklist can be tightened later without a migration. Do **not** copy the NAFEZA ACI document list into the Pre-Alert rule |
| **Q-P02** | Who decides that a Pre-Alert is "complete", and what happens when data arrives or changes after the job is open (amended invoice, new HBL, revised ETA)? | OPS · DEFER | Treat post-creation changes as audited edits with history (BL, container, ETA events). No approval workflow in M1 |
| **Q-P03** | Is an incomplete intake / Pre-Alert staging concept needed (e.g. a Pre-Alert received before all data is in)? (BD-P08) | DEFER · OPS | Not in M1. If it is needed, model it as a separate *Pre-Alert intake* record that can later be converted, never as a `draft` Import Job (see [DOMAIN_MODEL.md](DOMAIN_MODEL.md) §4.1) |
| **Q-P04** | Does the creator remain the job's permanent assigned operator? Is there one assignee per job, or one per department (Documentation, Operations, Customs)? (BD-P07a, BD-P07b) | **BLOCKS M1-C** (assignment UI) · OPS | M1-B records `created_by` only (immutable). M1-C adds assignment as a separate concept; design it to allow several assignments with a role/department, even if the first UI shows one |
| **Q-P05** | Job number format: prefix, year, per-tenant or per-branch sequence, reset yearly? | **BLOCKS M1-B** | Tenant-wide, gap-tolerant sequence generated inside the creation transaction with a lock, e.g. `IMP-2026-000184`. Prefix and per-branch numbering left configurable for later |
| **Q-P06** | Can a job be cancelled? By whom, and what happens to its ACID (can it be reused on a new job)? | DEFER (M1-C) · OPS | Cancellation as a lifecycle state with reason and audit; no hard delete of jobs |
| **Q-P07** | Which roles exist in practice (Documentation, Operations, Customs, Finance, Supervisor)? Should Claude seed suggested roles, or will Tenant Admins compose roles from permissions? | **BLOCKS M1-C** (role seeding) · OPS | Ship granular permissions only. Seed no new roles beyond what exists until the owner confirms; Tenant Admin assigns permissions to roles |
| **Q-P08** | Which branch becomes the job's primary branch on creation — the creator's primary branch, or a chosen one? Can a Documentation employee open a job for another branch? | **BLOCKS M1-B** | Default to the creator's primary branch; allow choosing another branch only with a cross-branch create permission. Today every tenant has only `MAIN` (see Q-BR02), so this is low-risk |

## 2. ACID / ACI

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-A01** | What is the official ACID format: 19 digits (research per the owner's brief) or 16 (owner's recollection)? Any check digit or structure? | REG · P3 | Store the ACID as a **string** (never an integer: leading zeros, length change). Normalise whitespace. Put format checking in one replaceable validator. Until an official source is recorded in [RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md) §4–5, the validator only rejects empty/obviously malformed input (e.g. non-digits) and shows a **warning**, not an error, for an unexpected length. Neither stored report states a digit count |
| **Q-A02** | In MBL/HBL and consolidated cases, which ACID must exist when the job is created: the master ACID, at least one house ACID, or any? | **BLOCKS M1-B** (creation rule wording) · OPS | Require **at least one** ACI record at creation. Allow additional ACI records, each optionally linked to a BL, later |
| **Q-A03** | May the same ACID appear on more than one job (split handling, re-opened file, cancelled job)? | **BLOCKS M1-B** (duplicate rule) · OPS · REG | Unique per job (no duplicate rows inside one job). Across jobs: detect and require explicit confirmation, no database uniqueness constraint, until answered (BD-A05) |
| **Q-A04** | Should the app track ACI lifecycle facts (verified, locked, amendment requested/approved)? (X3 in the synthesis) | DEFER (M3) | Not in M1. The ACI record can gain optional timestamps later without changing its identity |
| **Q-A05** | Does the target company ever request the master ACID itself in consolidated cases, or is the ACID always provided by the importer? | OPS · DEFER | Affects M3 only |
| **Q-A06** | Can an ACID be corrected after job creation? By whom? | **BLOCKS M1-B** (edit rules) · OPS | Allowed with a dedicated permission, a mandatory reason and an audit entry recording old and new values. Removing the last ACI record of a job is refused (BD-P05 invariant) |
| **Q-A07** | What is the importer tax ID / exporter identifier format to store with parties? | REG · DEFER | Store as strings without format validation in M1 |

## 3. Parties (master data)

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-D01** | Which party roles are needed on a job (customer, importer, consignee, notify, shipper/exporter, origin agent, carrier, customs broker, trucker)? Is the customer always the importer? | **BLOCKS M1-B** (role list) · OPS | One tenant-wide `parties` table (a legal entity), roles attached **per job** (`job_parties`). Start with: customer, importer, consignee, notify party, shipper/exporter, origin agent, carrier. Role list in code as an enum that can grow |
| **Q-D02** — **RESOLVED → BD-D01** | Are parties shared across all branches of a tenant? | DEFER (answered 2026-10-23) | Yes, tenant-wide master data; visibility of *jobs* is what is branch-scoped |
| **Q-D03** — **RESOLVED → BD-D02** (for M1) | How are duplicate parties detected (same company typed twice)? | DEFER · OPS (answered 2026-10-23: a warning, then save after confirmation; merge tooling stays deferred) | Soft warning on similar name / same tax ID; merge tooling later |
| **Q-D04** | Are carriers and terminals master data the platform should seed (global reference) or tenant-maintained? | DEFER | Tenant-maintained parties in M1. A central read-only reference list (UN/LOCODE, carrier SCAC) is a later, explicit decision (R1 L182) |

## 4. Bills of lading, containers, booking

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-B01** | Does the target company handle consolidated MBL/HBL cases today? | OPS · DEFER | Support the structure (BL parent/child, many ACI records) from M1; build no consolidated-specific UI until confirmed |
| **Q-B02** | Can a job have more than one MBL? | OPS | Structurally yes; the M1 UI may show one MBL with its HBLs |
| **Q-B03** | Is a BL number unique per carrier? How to treat the same MBL on two jobs? | OPS · DEFER | Detect and warn; no hard uniqueness |
| **Q-C01** | What container statuses does Operations actually use? | OPS · DEFER (M2) | M1 stores container identity only; per-container state comes in M2 from events |
| **Q-C02** | Is strict ISO 6346 check-digit validation acceptable, or are there legitimate non-conforming numbers (e.g. shipper-owned containers)? | OPS · DEFER (M1-C/M2) | Validate and warn; allow an explicit override that is recorded |
| **Q-K01** | Is booking information ever entered by the destination office? | DEFER | Booking stays an optional reference (BD-F03); not built in M1 |

## 5. Branch ownership and visibility

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-BR01** | What visibility scopes are needed: own branch, selected branches, all branches? Should an assignee from another branch see a job automatically? (BD-T07, BD-T08) | **BLOCKS M1-C** (list scoping) · OPS | Two permissions to start: view jobs of own branch, view jobs of all branches. Explicit per-user extra branches later. Assignment does not grant visibility in M1 |
| **Q-BR02** | Tenants currently have only the default `MAIN` branch and Tenant Admins cannot create branches (handover §2.1). When is branch management needed for FCL? | **BLOCKS M1-C** for multi-branch testing · OPS | Build FCL branch scoping against the existing `branches` table and test it with seeded branches; keep branch-management UI a separate, explicitly approved task |
| **Q-BR03** | Primary branch change (transfer): who approves, and what happens to assignments and open tasks? (BD-T09) | DEFER (after M1) | Not in M1; the primary branch is set at creation and not editable in the UI. The confirmed requirements (special permission, reason, audit, review of assignments/tasks) are recorded for the later feature |

## 6. Security, impersonation and audit

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-S01** — **RESOLVED → BD-SEC04** | May platform support staff and Tenant Admins in employee view **read** FCL data during impersonation? The current allowlist allows the generic abilities `viewAny` and `view` and every GET request ([PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §5.4), so FCL pages using those ability names would be readable | **BLOCKS M1-B** (policy naming) | Use FCL-specific ability names (e.g. `fcl.jobs.view`) so FCL reads are **denied** during impersonation by default; add them to the allowlist only after the owner decides. FCL writes stay denied (BD-SEC01) |
| **Q-S02** | Which FCL actions are "sensitive" (cancel job, change branch, edit ACID, later customs release, finance approval, document deletion)? | DEFER per milestone | Classify each new action when it is built; sensitive actions need their own permission and reason |
| **Q-S03** — **RESOLVED → BD-AUD01** | No tenant-side business audit exists today ([PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §4.8). Should FCL audit live in the tenant DB only, and should the new tenant audit later also cover employee/role changes? | **BLOCKS M1-A** (audit design) | Tenant DB only, append-only, written explicitly by actions (same style as `PlatformAuditLog`). FCL data never goes to `fcl_central` (BD-T10). Record the original actor if writes are ever allowed during impersonation. Extending it to employees/roles is a separate, later decision |

## 7. Regulatory and API verification

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-R01** | Locate and record URLs, access dates and exact statements for sources S1–S18 ([RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md) §5) and for the R1 external claims | REG | No hard-coded regulatory rule relies on an unverified source |
| **Q-R02** | Does NAFEZA offer an integration a forwarder SaaS may legally use (the historical shipping-line Web Service documentation mentioned by the owner)? Under what authorization and terms? | REG · DEFER (M6+) | No integration. The ACID validator is a *format* check, never presented as NAFEZA verification (BD-A03) |
| **Q-R03** | Legal retention, privacy and deletion rules for shipment documents and personal data in Egypt | REG · DEFER (M2) | Needs qualified legal advice; not invented in software (R1 L1015) |
| **Q-R04** | Do the ACI 48-hours-before-shipping and manifest timings matter for a destination job opened after shipping? | REG · DEFER | Store facts only; no deadline logic |

## 8. Operational workflow (later milestones)

All **OPS + DEFER**. They come from R2 L1036–1055 and R1 L1179 and should be answered in a walkthrough with real files.

| ID | Question | Needed by |
|---|---|---|
| Q-W01 | Who tracks the Arrival Notice and line invoices? | M2 |
| Q-W02 | Who opens the customs declaration internally, and which status names does the customs team use? | M3 |
| Q-W03 | Who obtains the shipping-line Delivery Order, and who pays line charges (Finance or Operations)? | M3 |
| Q-W04 | How does the team know the terminal is ready for pickup? | M3/M4 |
| Q-W05 | Own truck fleet or subcontractors? Who books trucks? | M4 |
| Q-W06 | Who chooses the empty-return depot and tracks the deadline? Source of free-time data? | M4 |
| Q-W07 | When does Operations consider a job closed? When does Finance? | M4/M5 |
| Q-W08 | How are demurrage/detention calculated today? | M4/M5 |
| Q-W09 | Which Excel sheets are still the source of truth, and which hidden rules do they contain? | M1 onward (useful early) |
| Q-W10 | Charges: currencies, taxes, invoice requirements, approval, vendor bills | M5 |

## 9. Architecture and product

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| **Q-X01** — **RESOLVED → BD-T13** | The code is a conventional Laravel layout grouped by technical type, not module folders ([PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §4.1). Should FCL get its own base folder (e.g. `app/Fcl/...`) or follow the existing feature-subfolder convention? | **BLOCKS M1-A** | Follow the existing convention: `app/Actions/ImportJobs/`, `app/Models/`, `app/Enums/`, `app/Policies/`, `resources/views/pages/import-jobs/`. Prefix FCL-specific enums/classes clearly. No new base folder without approval (AGENTS.md) |
| **Q-X02** | When a subscription expires, operational routes are fully blocked today (no read-only mode). Should tenants be able to read or export FCL data while expired? | DEFER (before production) | Keep FCL inside `subscription.active`, same as today |
| **Q-X03** | Is an Arabic / RTL user interface required, and from which milestone? No translations exist today | DEFER · OPS | English UI with `__()` everywhere so translation stays possible; decide before customer pilots |
| **Q-X04** | REST API in the MVP, or Livewire only? (R1 proposes an API in M1) | DEFER (M6) | Livewire only until an integration needs it; all rules in actions so an API can reuse them (R1 L692–702) |

## 10. Documents (M2)

| ID | Question | Class | Provisional approach (not approved) |
|---|---|---|---|
| Q-DOC01 | Storage backend (local private disk vs S3-compatible), malware scanning (e.g. ClamAV), size limits | BLOCKS M2 | Private tenant-prefixed storage, quarantine → scan → promote, as in R1 L952–991. Uploads stay disabled until then (BD-SEC03) |
| Q-DOC02 | Which document types and which readiness profile (Egypt ACI profile vs Pre-Alert checklist)? | BLOCKS M2 · OPS | Configurable requirement profiles; NAFEZA's list as the first ACI profile once S2 is verified |
