# Egyptian FCL Ocean Import — Operational Workflow

**Status:** proposed end-to-end workflow · **Created:** 2026-10-22 (Phase 0)

This is the reference workflow the product is designed around. Only the parts tagged **CONFIRMED** are owner decisions. Everything tagged **PROPOSED** comes from the research (mainly R2's swimlane, R2 L619–633) and must be validated with the target company's real staff and files before it becomes a rule (R1 L1177–1179; R2 L1036–1119).

| Tag | Meaning |
|---|---|
| **CONFIRMED** | Owner decision ([BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md)) |
| **OFFICIAL** | Egyptian Customs / NAFEZA process as reported by R2. Source URLs still need verification ([RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md) §5) |
| **CARRIER** | One carrier's or terminal's practice; never a global rule |
| **PROPOSED** | Research or design proposal awaiting operational validation |

---

## 1. End-to-end flow

```
 (before our system)              ┌─────────────── IMPORT JOB (our workspace) ────────────────┐
 Importer files ACI in NAFEZA     │                                                           │
 → ACID issued  [OFFICIAL]        │  1 Pre-Alert received by Documentation  [CONFIRMED]       │
 Exporter docs via CargoX         │  2 Job opened by the receiver, ACID mandatory [CONFIRMED] │
                                  │  3 BL structure (MBL / HBLs), containers, parties         │
                                  │  4 Transit monitoring: ETA changes, Arrival Notice        │
                                  │  5 Arrival / discharge — per container                    │
                                  │        ┌──────────────┼───────────────┐                   │
                                  │  6 Customs decl.  7 Carrier release  8 Terminal ready     │
                                  │    + customs        / DO               (per container)    │
                                  │      release                                              │
                                  │        └──────────────┼───────────────┘                   │
                                  │  9 Delivery (1..n trips, per container): gate-out → POD   │
                                  │ 10 Empty return — per container                           │
                                  │ 11 Operational closure      12 Financial closure          │
                                  └───────────────────────────────────────────────────────────┘
```

Steps 6, 7 and 8 run in parallel and independently [CONFIRMED BD-F09]. Steps 5 and 8–10 happen **per container** [CONFIRMED BD-F08]. Steps 11 and 12 are separate [CONFIRMED BD-F10].

---

## 2. Stage by stage

### Stage 0 — ACI / ACID (upstream of the job)

- **OFFICIAL.** Maritime ACI has been mandatory since 2021-10-01. Data and documents must be available at least 48 h **before shipping** (R2 L71). The importer obtains the ACID in NAFEZA; foreign documents travel through CargoX (R2 L127). Lines and forwarders can verify and lock an ACID before shipping (R2 L73).
- **CONFIRMED.** In the target workflow the ACID exists before the Pre-Alert stage (BD-P04). Our system does **not** perform ACI filing, verification or locking (BD-A03).
- **PROPOSED (M3+).** Optionally record ACI facts (verified/locked) if the company tracks them (Q-A04).

### Stage 1 — Pre-Alert receipt

- **CONFIRMED.** The **Documentation Department** receives the Pre-Alert (BD-P01).
- **CONFIRMED.** The Pre-Alert is expected to be **complete** when the job is opened (BD-P03).
- **OPEN.** The exact contents of a complete Pre-Alert (BD-P06, Q-P01). R2 L87–104 lists typical contents, explicitly *not* an official list:
  - origin agent reference;
  - customer/importer and shipper/exporter;
  - MBL/HBL references and carrier;
  - POL/POD, vessel/voyage, ETD/ETA;
  - containers;
  - invoice, packing list;
  - ACID;
  - special instructions.
- **Sources.** An origin agent (Origin Agent case) or the shipping line / customer (Direct Shipping Line case) (BD-S03).

### Stage 2 — Import Job creation

- **CONFIRMED.**
  - The employee who received the Pre-Alert opens the job (BD-P02).
  - No ACID → no job (BD-P05).
  - The creator is recorded (BD-P07a).
  - The job belongs to one primary branch (BD-T06).
- **OPEN.** Whether the creator stays the assigned operator (BD-P07b); job numbering (Q-P05); branch on creation (Q-P08).
- **Not allowed.** A "draft" job opened with incomplete data (BD-P08; conflict X1 in the synthesis).

### Stage 3 — Documentation: BLs, containers, parties, documents

- **PROPOSED.** Documentation records:
  - the MBL and any HBLs (R2 L621–624, "MBL/HBL review");
  - container numbers and parties;
  - links of each ACID to the right BL in MBL/HBL or consolidated cases.
- **OFFICIAL.** The usual ACI document package is commercial invoice, packing list, BL **copy**, certificate of origin, plus cargo-specific certificates (R2 L129–136). This is the basis for a future document-readiness profile (M2), **not** the Pre-Alert checklist.

### Stage 4 — Ocean transit

- **PROPOSED.** Operations monitors (R2 L208–219):
  - ETA changes, kept as a history;
  - transshipment;
  - the Arrival Notice;
  - document completeness;
  - customs file readiness;
  - known carrier charges.

  Customs may prepare the file early.
- **CARRIER.** Lines publish Arrival Notices and tracking. CMA CGM Egypt and Hapag-Lloyd Egypt are examples (R2 L220).

### Stage 5 — Arrival and discharge

- **PROPOSED.** Vessel ATA and per-container discharge are separate facts. Some containers may **not arrive** with the vessel (R2 L245–264).
- **OFFICIAL.** NAFEZA has a service to notify customs of non-arrived containers (R2 L251).

### Stage 6 — Customs declaration and customs release

- **CONFIRMED.** The in-house Customs team (BD-S04).
- **OFFICIAL.** NAFEZA covers:
  - Import Declaration submission and status tracking;
  - examination/inspection scheduling;
  - electronic fee payment;
  - a unified release permission (R2 L271–273).
- **PROPOSED.** Customs has its own record, status and events, including holds, examinations and document requests (R2 L277–306). The event names are design vocabulary, not NAFEZA statuses. The team's real status names: Q-W02.

### Stage 7 — Carrier release / Delivery Order

- **OFFICIAL.** NAFEZA has a separate "Submit a Delivery Order" service (R2 L318).
- **CARRIER.** Hapag-Lloyd Egypt requires settled charges and documents before a DO / container release: an original BL or sea waybill/telex equivalent, a delegation letter, customer ID, tax ID and commercial registration (R2 L314). Maersk similarly ties release to customs, charges and documents (R2 L316). These belong in a **carrier profile**, not global code.
- **OPEN.** Who obtains the DO and who pays line charges (Q-W03).

### Stage 8 — Terminal readiness

- **PROPOSED.** A gate per container, independent of customs and carrier release (R2 L341–365).
- **CARRIER.** Alexandria's Logistics Services Center handles inspection/examination/release and excludes reefer and hazardous containers (R2 L348). Cargo type can change the path.
- **OPEN.** How staff know the terminal is ready (Q-W04).

### Stage 9 — Trucking and delivery

- **PROPOSED.** A delivery covers one or more containers; a job may need several trips. The sequence is gate-out → delivered → POD (R2 L372–388). Delivery never starts automatically on customs release.
- **Field-validation priority.** No public official SOP exists (R2 L368–370). Own fleet or subcontractors: Q-W05.

### Stage 10 — Empty return

- **PROPOSED.** Per-container empty depot, due date, return time and reference. Free time is a sourced value, never a hard-coded number of days (R2 L390–415, L713–733). Responsibility: Q-W06.

### Stage 11 — Operational closure

- **CONFIRMED.** Distinct from financial closure (BD-F10).
- **PROPOSED criteria** (R2 L420–430):
  - all containers handled;
  - deliveries complete;
  - required empty returns done;
  - no blocking tasks;
  - no unresolved customs or carrier exceptions.

  The real criteria are Q-W07.

### Stage 12 — Financial closure

- **CONFIRMED.** Separate (BD-F10).
- **PROPOSED.** Revenue and cost processes complete according to company policy (R2 L436). Defined in M5.

---

## 3. Responsibilities and hand-offs (RACI-style)

**Legend:** R = does the work · A = accountable · C = consulted · — = not involved. Only cells marked ✔ are owner-confirmed; all others are R2's initial recommendation (R2 L619–631) and need validation.

| Stage | Documentation | Operations | Customs | Finance | External |
|---|---|---|---|---|---|
| 0 ACI / ACID | C (follows ACID) | — | C | — | Importer, exporter, NAFEZA, CargoX |
| 1 Pre-Alert receipt | **R ✔** | — | — | — | Origin agent / shipping line |
| 2 Job creation | **R ✔** (the receiving employee) | — | — | — | — |
| 3 BLs, containers, documents | R | C | C | — | Agent, carrier |
| 4 Transit monitoring | C (doc updates) | R | C (prepares file) | C (charge estimates, if policy) | Carrier |
| 5 Arrival / discharge | C (Arrival Notice) | R | C | C (carrier/local invoices) | Terminal, carrier |
| 6 Customs + customs release | C (document support) | C (exceptions) | **R** (in-house team ✔ exists) | C (duties) | NAFEZA / Customs |
| 7 Carrier release / DO | C (documents) | R? | — | C (line charges)? | Shipping line |
| 8 Terminal readiness | — | R | C | — | Terminal |
| 9 Delivery | — | R | C (release gate) | C (transport costs) | Trucker, terminal |
| 10 Empty return | — | R | — | C (D&D exposure) | Carrier, depot |
| 11 Operational closure | C (file completeness) | R/A | C | — | — |
| 12 Financial closure | — | C | — | R/A | — |

**Key hand-offs to validate** (R2 L633):
- Documentation → Operations after job creation: does ownership move, or is there co-ownership (Q-P04)?
- Customs: does it own the job from a point onward, or only a sub-process?
- Who owns the DO and line charges (Q-W03)?

---

## 4. Variations

### 4.1 Origin Agent case

| Aspect | Behaviour |
|---|---|
| Source channel | Origin agent [CONFIRMED BD-S03] |
| Parties | The origin agent party is present. Typically an MBL (agent's carrier booking) plus an HBL issued by the agent (R2 L448–466) |
| ACID | At least one is required at creation [CONFIRMED]. With MBL + HBL, which level must exist first is Q-A02 |
| Booking | Upstream at origin; reference only [CONFIRMED BD-F03] |

### 4.2 Direct Shipping Line case

| Aspect | Behaviour |
|---|---|
| Source channel | Direct shipping line |
| Origin agent | None [CONFIRMED BD-F04] |
| BLs | Usually the line's MBL only; HBL absent (R2 L527–538) |
| Carrier interaction | Arrival Notice, invoices, tracking and DO come from the line's own channels (R2 L525) |
| Everything else | Same as the origin-agent case |

### 4.3 MBL + several HBLs / consolidated

- **OFFICIAL.** Each sub-bill can carry its own ACID, plus a master ACID for the consolidated BL (R2 L543–559).
- **Structure:** supported from M1 (several ACI records, BL parent/child) [CONFIRMED BD-A05].
- **Operational handling:** whether the company handles consolidations at all is OPS question Q-B01. No dedicated UI until it is confirmed.

### 4.4 Multiple containers and partial operations

The job shows aggregated readiness, for example "Containers 1/3 completed · Customs partial · Empty return 1 outstanding" (R2 L611–617), never one status [CONFIRMED BD-F06, BD-F08]. Example: A delivered and returned, B delivered with empty outstanding, C not yet arrived.

---

## 5. Release gates (M3–M4, proposed)

| Gate | Granularity | Example states (design vocabulary, not official) |
|---|---|---|
| ACI readiness | job / ACI record | Present · (later) Verified / Locked |
| Document readiness | job | Complete · Incomplete (M2) |
| Vessel | job | In transit · Arrived |
| Customs declaration | job (or BL) | Not started · In progress · Exception |
| Customs release | job / container | Pending · Released |
| Carrier release / DO | BL | Pending · Released |
| Terminal readiness | container | Pending · Ready |
| Delivery | container / delivery | Not planned · Planned · In progress · Delivered |
| Empty return | container | Pending · Returned |
| Operational closure | job | Open · Complete |
| Financial closure | job | Open · Complete |

Delivery may be planned for a container only when every applicable gate for that container is satisfied. The set of applicable gates is configurable per carrier, terminal and cargo profile later (R2 L358–365).

---

## 6. Exceptions (from R2 L693–707, all PROPOSED handling)

| Exception | Proposed system behaviour | Milestone |
|---|---|---|
| Missing ACI/Pre-Alert document | Blocks readiness; task; alert | M2 |
| Incorrect ACI data / ACID correction | Permissioned correction with reason and audit; never a silent overwrite | M1 (correction), M3 (amendment tracking) |
| ACI locked | Record the fact; amendments are exceptions | M3 (optional) |
| Container not arrived | Container-level exception, job continues | M2 |
| Customs examination / hold | Customs event + task, not a job status | M3 |
| Customs released, DO not ready | Delivery stays blocked | M3 |
| Carrier charges/documents pending | Carrier release exception | M3 |
| Lost original BL | Carrier-specific procedure (e.g. Hapag-Lloyd: police report + LOI); no global rule | M3 |
| ETA changed | Append an ETA event; re-plan tasks | M1 (event), M2 (tasks) |
| Only some containers delivered | Partial delivery | M4 |
| Empty return late | Per-container overdue alert | M4 |
| Hazardous / reefer cargo | Cargo profile changes the gate set / SOP | M3/M4 |

---

## 7. What the system must never encode as fact (until verified)

- Clearance or terminal durations ("customs takes X days") (R1 L1140).
- Free days or demurrage/detention rules (R2 L716–721).
- Port-specific workflow classes (R2 L751).
- Official NAFEZA status names or an API contract (R1 L917; R2 L307).
- The NAFEZA document list as the Pre-Alert checklist (BD-P06).
- An ACID format rule before Q-A01 is answered (BD-A02).
