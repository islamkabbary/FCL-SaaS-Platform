# FCL Conceptual Domain Model

**Status:** conceptual design, **not** a schema · **Created:** 2026-10-22 (Phase 0)

This document defines concepts, ownership, cardinalities and invariants. It does not define migrations, column types or models; those are produced per step in [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) and must be checked against this document.

Every invariant is tagged:
- **[C]** confirmed by the owner, with a decision ID from [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md);
- **[P]** proposed, not yet approved;
- **[?]** open, see [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md).

---

## 1. Placement

```
fcl_central (control plane)                 fcl_tenant_<uuid> (one per company)
─────────────────────────────               ─────────────────────────────────────
tenants, domains                            branches, users, roles, permissions   (exist)
platform admins, platform audit             ── FCL ──────────────────────────────
plans, subscriptions, seats                 parties
SaaS billing charges/payments               import_jobs ── job_parties
support sessions                            aci_records
                                            bills_of_lading ── bl_containers
NO FCL DATA HERE (BD-T10, BD-T11)           containers
                                            job events / audit
                                            (later) tasks, documents, customs,
                                              releases, deliveries, charges
```

- Logical ownership by the tenant is **never** a cross-database foreign key.
- Inside the tenant DB, normal foreign keys apply.
- FCL models use the default (tenant) connection only (BD-T03).

---

## 2. Concept map

```
                         Branch (exists)            User (exists)
                            │ 1                        │ created_by / assignments
                            │                          │
Party ─────< JobParty >──── ImportJob ─────────────────┘
(master data,  (role per     │ 1
 tenant-wide)   job)         │
                             ├──< AciRecord >───────┐ (optional link to one BL)
                             │                      │
                             ├──< BillOfLading ─────┘
                             │        │  parent (MBL ⟵ HBL), self-reference
                             │        │
                             │        └──< BlContainer >──┐
                             │                            │
                             ├──< Container ──────────────┘
                             │
                             ├──< JobEvent          (timeline facts: created, ETA changed, …)
                             ├──< Task              (M1-C minimal or M2)
                             ├──< DocumentLink >── Document   (M2)
                             ├──< CustomsDeclaration          (M3)
                             ├──< ReleaseGate                 (M3; per job/BL/container)
                             ├──< Delivery >──< DeliveryContainer  (M4)
                             └──< Charge                      (M5)
                             (Booking: optional reference, later)
```

---

## 3. Concepts

### 3.1 Import Job (aggregate root, workspace)

**What it is.** The destination forwarder's operational file for one FCL import shipment. Users open it to understand and operate the shipment (BD-F01). It is **not** a container for every column (BD-F02), and it has **no single shipment status** (BD-F06).

**Owns:**
- identity: a tenant-unique job number [P PR-06];
- the primary branch [C BD-T06];
- the source channel [C BD-S03];
- the creator [C BD-P07a];
- a coarse lifecycle [P PR-01];
- operational and financial closure facts [C BD-F10, P PR-02];
- route and transport summary values, for example POL/POD as UN/LOCODE where possible, vessel/voyage and current ETA [P].

**Invariants:**
- I-J1. A job always has **at least one ACI record**: from the creation transaction onward, and no operation may remove the last one [C BD-P05].
- I-J2. A job has exactly one primary branch. Changing it is a dedicated, permissioned, reasoned, audited operation that is **not** in M1 [C BD-T06, BD-T09].
- I-J3. `created_by` is set once and never changes [C BD-P07a]. Assignment is a separate concept [? Q-P04].
- I-J4. Lifecycle state does not encode transport, customs, release or delivery progress. Those belong to their own concepts [C BD-F06].
- I-J5. Operational closure does not imply financial closure, and the reverse is also false [C BD-F10].
- I-J6. Vessel + voyage is never an identity or uniqueness key (R1 L141; R2 L203).
- I-J7. The current ETA is a convenience value. Every change is also recorded as an event with old and new value, actor and time (R2 L222–240) [P].
- I-J8. No `draft` state; a job exists only once formally created [C BD-P03, BD-P08].

**Readiness (derived, never stored as one status).** The workspace shows separate indicators:
- ACI
- Documents (M2)
- Vessel/arrival
- Customs (M3)
- Customs release, carrier release / DO and terminal readiness (M3)
- Delivery and empty return (M4)
- Operational closure and financial closure

Display vocabulary is a product proposal, not official NAFEZA wording (R2 L689).

### 3.2 Party (master data) and Job Party (role)

- **Party.** A legal entity known to the tenant: importer, customer, exporter, agent, carrier, broker or trucker. It is tenant-wide, independent of jobs, and may exist **without** any ACID or job [C BD-P09, BD-D01].
- **Job Party.** Links a party to a job **in a role**: customer, importer, consignee, notify party, shipper/exporter, origin agent or carrier. Later roles may include customs broker, trucker and empty depot.
- **Cardinality.** A job has 0..n parties per role, and the same party can play several roles on one job (customer = importer = consignee is common). A party has 0..n jobs.
- **Invariants:**
  - I-P1. Origin agent is optional [C BD-F04].
  - I-P2. Which roles are required at creation is open [? Q-P01, Q-D01]. The proposal requires only the customer [P PR-03].
  - I-P3. Importer tax ID and exporter identifiers are stored as attributes of the party, without format validation until verified [? Q-A07].
  - I-P4. Parties are shared by every branch of the company; branch scope applies to jobs, not to parties [C BD-D01].
  - I-P5. A likely duplicate (same name or tax number after normalisation) is a warning: the user may confirm and keep both. There is no uniqueness rule [C BD-D02].
- **As built (M1-A6, 2026-10-30).** `App\Models\Fcl\Party` with legal name, tax number, country, notes and active flag [C BD-D03]; see [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) §4.4. Job Party is not built yet (M1-B).
- **Extension.** Contacts, addresses, a party's branch affinity, merge/dedupe tooling, and a central read-only carrier/port reference (a separate explicit decision, R1 L182).

### 3.3 ACI Record (ACID)

**What it is.** A record of an Advance Cargo Information identifier (ACID) relevant to the job. It is a domain concept, not a job column [C BD-A04].

**Cardinality:**
- A job has **1..n** ACI records [C BD-P05, BD-A05].
- An ACI record optionally relates to **one** bill of lading: the master ACID to the MBL, a house ACID to its HBL (R2 L547–559) [P PR-04].
- Whether one ACID may appear on several jobs is open [? Q-A03].

**Invariants:**
- I-A1. The ACID is stored as a **string**, normalised (trimmed, internal spaces removed). Never store it as a number.
- I-A2. Format validation lives in one replaceable validator. Until Q-A01 is resolved it must not hard-reject a 16- or 19-digit value [C BD-A02].
- I-A3. A format check is **never** labelled or stored as "verified"; external verification is a different fact (`verified_at` / source, M3+) [C BD-A03].
- I-A4. No duplicate ACID within the same job.
- I-A5. Cross-job duplicates are detected and need explicit confirmation, not a DB constraint [P PR-05].
- I-A6. Correcting an ACID after creation is a permissioned edit with a reason and an audit entry recording old and new values [? Q-A06].

**Extension (M3+, only if needed):** issued/verified/locked timestamps, amendment history, the importer/exporter parties of the ACI, ACI type (direct/house/master/consolidated) [? Q-A04].

### 3.4 Bill of Lading

**What it is.** A transport document: master (MBL) or house (HBL). It has its own identity, type, number, issue data and lifecycle (R1 L92–93).

**Cardinality:**
- A job has 0..n BLs. A typical job has 1 MBL, or 1 MBL plus 1..n HBLs.
- An HBL has an optional parent MBL, a self-reference (R2 L567–573 suggests `bill_relationships`; a parent pointer is enough until a case needs many-to-many) [P].
- BL ↔ Container is **many-to-many** [C BD-F07].

**Invariants:**
- I-B1. HBL is never mandatory [C, R2 L538].
- I-B2. A house BL's parent, if set, is a master BL on the same job.
- I-B3. A BL number is not assumed globally unique. Duplicates are detected per tenant and need confirmation [? Q-B03].
- I-B4. Whether a job may hold more than one MBL is open. The structure allows it [? Q-B02].
- I-B5. Carrier-specific release facts (original BL surrendered, telex release, sea waybill) belong to Carrier Release (M3), not to BL booleans.

**Extension:** freight terms, issue place/date, BL type (original / sea waybill / eBL), amendments, DCSA BL 3.x mapping.

### 3.5 Container

**What it is.** A piece of FCL equipment in this job. Its lifecycle is independent per container [C BD-F08].

**Cardinality.** A job has 0..n containers. A container links to 1..n BLs of the same job.

**Invariants:**
- I-C1. The container number is validated against ISO 6346 (owner code, serial, check digit) through a replaceable validator. The override policy is open [? Q-C02].
- I-C2. The same container number may appear on other jobs over time (equipment is reused), so there is **no** tenant-wide uniqueness. It is unique **within a job** [P].
- I-C3. A container's state is per container and derived from its events (M2): discharged, not arrived, customs hold, released, gate-out, delivered, empty returned. The job never holds "the container status" (R2 L257–264).
- I-C4. Empty-return due dates and free time are per container, sourced and verified, never a hard-coded number of days (R2 L397–415, L716–732).

**Extension (M2/M4):** ISO size/type code, seal number(s), weights/VGM, discharge time, availability, free time, empty depot, return timestamp.

### 3.6 Events and timeline

- **Job Event.** An immutable fact about the job or one of its children, for example: job created, ACID added/corrected, BL added, container added, ETA changed, vessel arrived, container discharged. It records the actor (or source), the time, the subject (job / BL / container) and payload metadata [P PR-09].
- **Audit vs event.**
  - Audit answers "who changed what": every write, with before/after values.
  - Events answer "what happened to the shipment": business facts, possibly from external sources later.
  - M1 can implement both through one append-only tenant table plus a typed event vocabulary, or as two tables. This is decided in M1-A [? Q-S03].
- **Invariants:**
  - I-E1. Events are append-only.
  - I-E2. External events (M2+) carry a provider reference or deterministic key so that replays are idempotent (R1 L727, L879).
  - I-E3. ETA and ATA are distinct; ETA history is never overwritten (R1 L125).
  - I-E4. Event names are internal design vocabulary, not official NAFEZA or carrier status names (R2 L307).

### 3.7 Tasks (M1-C minimal or M2)

Work that needs to happen: who, what, due, status, and the job (and optionally the container) it concerns. Automatic task triggers come in M2. Tasks matter for branch transfer review (BD-T09).

### 3.8 Documents (M2)

`Document` (a secured object: storage key, detected MIME, size, SHA-256, scan status, version) is linked to job/BL/container/ACI record through `DocumentLink`, and checked against configurable `DocumentRequirement` profiles (R2 L650–671).

**Blocked by:** tenant-aware uploads (BD-SEC03) and Q-DOC01/02.

The NAFEZA 5 MB limit is an outbound ACI-package rule, not a storage limit (R1 L135).

### 3.9 Customs (M3)

`CustomsDeclaration` per job (or per BL/ACID if real cases require it), with its own status, inspection/examination, holds and customs events. It is an internal workflow of the in-house team [C BD-S04]; no NAFEZA filing [C BD-A03]. Status vocabulary is to be validated with the customs team [? Q-W02].

### 3.10 Release gates (M3)

These are independent gates [C BD-F09]:
- customs release;
- carrier release / delivery order (per BL and carrier; carrier-specific prerequisites live in a carrier profile, not global code);
- terminal readiness (per container; may depend on cargo type, e.g. reefer/hazardous, R2 L348).

Delivery readiness = all applicable gates are satisfied, and the gate set is configurable (R2 L358–365).

### 3.11 Delivery and empty return (M4)

A `Delivery` covers **one or more containers**, and a job may need several deliveries (R2 L388). Gate-out, delivered and POD are delivery facts. Empty return is a per-container fact. Delivery never starts automatically on customs release.

### 3.12 Charges and job profitability (M5)

Revenue and cost lines per job (optionally per container/BL), linked to parties, with exact decimal amounts and explicit currency. These are tenant data and are **entirely unrelated** to SaaS billing in `fcl_central` [C BD-T12]. Financial closure is a fact owned here [C BD-F10].

### 3.13 Booking (optional reference, later)

A carrier/origin booking reference. It is optional for a destination import job [C BD-F03]. It is not built until a real case needs more than a reference string.

---

## 4. Dangerous assumptions to avoid

| Assumption | Why it is wrong | Correct model |
|---|---|---|
| One job per BL | A job may carry an MBL and several HBLs; consolidated cases exist (R2 L543) | BLs are children of the job, with a parent/child hierarchy |
| One ACID per job / `import_jobs.acid_number` | Sub-bills can each have an ACID plus a master ACID (R2 L177) | ACI records, 1..n per job [C BD-A04, BD-A05] |
| ACID globally unique across jobs | Unknown; cancelled or re-opened files and split handling | Detect and confirm; no DB constraint until Q-A03 |
| ACID as an integer or fixed 16/19-digit regex | Format not confirmed; leading zeros | String + replaceable validator [C BD-A02] |
| One BL ↔ one container | A BL covers several containers; consolidations share equipment | Many-to-many [C BD-F07] |
| One status for the shipment | Arrival, customs, release, delivery and return progress independently | Coarse lifecycle + per-domain state + derived readiness [C BD-F06] |
| Container state on the job | Containers arrive, clear and return separately (R2 L586–617) | Per-container state [C BD-F08] |
| Customs released ⇒ ready for delivery | The carrier DO and terminal are separate gates | Independent gates [C BD-F09] |
| Delivered ⇒ closed | Empty return and finance remain | Separate closures [C BD-F10] |
| Vessel + voyage identifies a shipment | Many shipments share a vessel (R2 L203) | Never a key |
| Origin agent / booking / HBL mandatory | Direct-line and destination cases | Optional [C BD-F03, BD-F04] |
| Creator = assigned operator | Not decided | Separate concepts [C BD-P07a, ? BD-P07b] |
| Draft job for incomplete pre-alerts | Owner requires complete Pre-Alert + ACID | No draft jobs; any intake staging is a separate concept [C BD-P03, ? BD-P08] |
| Fixed free days / clearance durations | Carrier- and terminal-specific, change over time | Sourced, verified values; recorded timestamps |
| Port-specific workflow classes | No verified public SOP per port | Configuration profiles (R2 L740–751) |
| Shipment money via SaaS billing code | Different domain and database | Separate finance domain [C BD-T12] |

### 4.1 If an intake / staging concept is ever approved (Q-P03)

It would be a **separate** entity, for example a "Pre-Alert intake", with its own minimal state. It holds received information until it is complete. Converting it creates a real Import Job, at which point every Import Job invariant (I-J1 ACID, I-J8 no draft) applies. It must never be an Import Job with a weaker state, so no query or report on Import Jobs needs to filter out half-created jobs.

---

## 5. Authorization model (conceptual)

- **Tenant.** Determined by the hostname only. No request ever supplies a tenant id (R1 L723).
- **Branch.** A user may act on a job if a permission grants that branch scope:
  - the job's primary branch is the user's primary branch, under an "own branch" permission;
  - or the user holds an "all branches" permission;
  - later, explicitly granted extra branches [P PR-13; ? Q-BR01].
- **Per action.**
  - Vocabulary (names approved in BD-D04; the full list of nine is in `App\Enums\TenantPermission`): `fcl.jobs.view`, `fcl.jobs.create`, `fcl.jobs.update`, `fcl.jobs.cancel`, `fcl.jobs.view-all-branches`, `fcl.aci.correct`, `fcl.parties.manage`. BLs and containers are covered by job update.
  - Later: `fcl.jobs.assign`, `fcl.jobs.change_branch`, then customs, release, delivery and finance permissions per milestone (R2 L941–978).
  - All are FCL-specific names [P PR-08].
- **Impersonation.** Writes are denied [C BD-SEC01]. Reads depend on Q-S01.
- **Default.** Employee gets nothing; Tenant Admin gets everything through sync [C BD-SEC02].

---

## 6. Extension points kept open by M1

| Later need | What M1 must not prevent |
|---|---|
| Consolidated MBL/HBL with several ACIDs | ACI records 1..n with an optional BL link; BL parent link |
| Per-container release and delivery | Containers as entities; BL ↔ container many-to-many |
| Carrier/terminal profiles | Carriers as parties; no carrier logic in job code |
| External events (DCSA, carrier APIs) | Event store with subject + source; idempotency key column reserved when integrations arrive |
| REST API | All rules in actions; Livewire components stay thin |
| Documents | Subjects (job/BL/container/ACI) have stable ids to link to |
| Branch transfer | Branch changes go through one action; assignments/tasks queryable by job |
| Intake staging (if approved) | No `draft` state baked into Import Job |
