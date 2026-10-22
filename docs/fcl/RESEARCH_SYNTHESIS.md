# FCL Research Synthesis

**Status:** Phase 0 knowledge base · **Written:** 2026-10-22 · **Owner of decisions:** Islam Kabbary

This document turns the two research reports into findings that affect *this* product. It is not a summary of each report and does not replace them. The originals are kept unchanged as historical sources:

| Ref | File | Language | Focus |
|---|---|---|---|
| **R1** | [research/01-global-fcl-research.md](research/01-global-fcl-research.md) | English | Global forwarding software patterns, standards (DCSA, ISO 6346, UN/LOCODE), multi-tenant architecture, security, M1–M6 roadmap |
| **R2** | [research/02-egypt-operations-research.md](research/02-egypt-operations-research.md) | Arabic/English | Egyptian FCL Ocean Import: ACI/ACID, NAFEZA, CargoX, MBL/HBL, customs, carrier DO, terminals, trucking, empty return, SOP questions |

References below use the form `R1 §"Section title"` or `R1 L117` (line number in the unchanged file).

> **Rule for every reader.** Where a research recommendation conflicts with an owner decision in [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md), the owner decision wins. The research finding is kept here for context only. See §6.

---

## 1. Evidence levels used in this knowledge base

Every claim in the FCL docs is tagged with one of these levels. They extend R2's own confidence scale (R2 L64–69).

| Tag | Meaning | Can it become a hard-coded rule? |
|---|---|---|
| **[OFFICIAL-EG]** | Stated by Egyptian Customs or NAFEZA, as reported by the research | Only after the source is re-checked (§5) |
| **[OFFICIAL-CARRIER]** | Stated by one shipping line or terminal | No. Carrier/terminal-specific; configuration at most |
| **[STANDARD]** | International standard (DCSA, ISO, UN/LOCODE) | Yes for data formats (e.g. ISO 6346 check digit), behind a replaceable validator |
| **[INDUSTRY]** | Pattern observed in commercial products (CargoWise, Magaya, GoFreight, Descartes) | No. Design input only |
| **[DESIGN]** | Architecture or product recommendation by the research or by Claude | No, until the owner approves it |
| **[OWNER]** | Confirmed by the project owner | Yes |
| **[CODE]** | Verified in the current repository | Describes what exists, not what should exist |
| **[OPEN]** | Unresolved; see [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) | No |

---

## 2. Findings that shape the product

### 2.1 The Import Job is the workspace, not the whole data model

- **[INDUSTRY]** Commercial forwarding systems give users one shipment workspace but keep bookings, bills, containers, events, documents, tasks and finance as separate objects (R1 L5, L73–79; Magaya's API exposes Bills, Bookings, Shipments, Events, Documents, Organizations and Invoices separately).
- **[STANDARD]** DCSA publishes separate standards for Booking, Bill of Lading, Arrival Notice and Track & Trace instead of one shipment status (R1 L7, L89–110).
- **Consequence:** the Import Job is the aggregate users open, but BLs, ACI records, containers, events, documents, tasks, customs, release, delivery and charges are distinct concepts with their own identity and state. Confirmed by the owner (BD-F01, BD-F02, BD-F05).

### 2.2 No single shipment status

- **[DESIGN]** R1 (L641–663) and R2 (L62, L600–617) both reject one `shipment_status` enum. A job can be arrived while customs is incomplete, customs-released while the carrier DO is pending, partly delivered while another container is still at the port.
- **[OFFICIAL-EG]** NAFEZA runs ACI, Import Declaration, Release and Delivery Order as separate services (R2 L62, L77, L691).
- **Consequence:** a coarse job lifecycle plus per-domain state and per-container state, with readiness *derived* for display. Owner-confirmed principle (BD-F06, BD-F09).

### 2.3 ACI/ACID is upstream of the Import Job

- **[OFFICIAL-EG]** Maritime ACI has been mandatory since 1 October 2021. Shipment data and documents must be available at least **48 hours before shipping** (R1 L13; R2 L71). This is *not* the manifest deadline, which is tied to vessel arrival (R2 L71). The two timings must not be confused.
- **[OFFICIAL-EG]** The Egyptian importer starts the ACI in NAFEZA and receives the ACID; documents from abroad travel through CargoX (R2 L127).
- **[OFFICIAL-EG]** Shipping lines and freight forwarders have a NAFEZA service to *verify* an ACID and *lock* ACI data before shipping (R2 L73, L1171).
- **[OWNER]** In the target workflow the ACID already exists before the Pre-Alert stage, and an Import Job must never be created without an ACID (BD-P04, BD-P05).
- **Consequence:** our system *records and references* ACIDs; it does not run the ACI filing. ACI lifecycle features suggested by R2 (issued/verified/locked timestamps, amendment history) are optional future fields, not M1 requirements.

### 2.4 One job may need more than one ACID

- **[OFFICIAL-EG]** In consolidated LCL/FCL cases each sub-bill has its own ACID, and the Egyptian forwarder requests a further ACID for the consolidated/master BL (R2 L6, L177, L543–559, L1173).
- **[OFFICIAL-EG]** Several shipments on the same vessel each have their own ACID handling (R1 L140; R2 L203). Vessel + voyage is never a shipment key.
- **Consequence:** ACID is a domain concept (an ACI record) linked to the job and optionally to a BL, not one column on `import_jobs`. This corrects R1, which modelled `import_jobs.acid_number` (R1 L318, L744). Owner-confirmed (BD-A04, BD-A05).

### 2.5 MBL/HBL structure and BL ↔ container relationships

- **[OFFICIAL-EG]** NAFEZA describes the freight forwarder's MBL/HBL role: the Egyptian agent enters the MBL ACID, and HBLs are cleared against the MBL on arrival (R2 L75, L201).
- **[DESIGN]** A BL may cover several containers, and in consolidations several BLs may concern the same container (R1 L667). HBL is optional (R2 L538). Booking is optional for a destination job (R1 L21, L122; R2 L182).
- **Consequence:** BLs are entities with a type (master/house) and an optional parent; BL ↔ container is many-to-many. Owner-confirmed (BD-F03, BD-F04, BD-F07).

### 2.6 Containers have independent lifecycles

- **[OFFICIAL-EG]** NAFEZA has a service to notify customs of **non-arrived containers**, which proves partial arrival is a regulated, real scenario (R2 L251, L591, L1178).
- **[STANDARD]** ISO 6346 defines container identification, check digit and size/type codes; validate against the standard, not a regex (R1 L104, L862, L1148).
- **Consequence:** state, discharge, release, delivery and empty return are tracked per container. Owner-confirmed (BD-F08).

### 2.7 Four separate release processes

- **[OFFICIAL-EG]** Customs release and the Delivery Order are separate NAFEZA services (R2 L77, L318).
- **[OFFICIAL-CARRIER]** Hapag-Lloyd Egypt requires settled charges and documents (original BL or sea waybill/telex release, delegation letter, customer ID, tax ID, commercial registration) before an import DO / container release (R2 L314). Maersk links release to customs, charges and documents (R2 L316). These are **carrier-specific** and must not become global rules.
- **[OFFICIAL-CARRIER]** Alexandria's Logistics Services Center (opened July 2025) handles inspection, examination and release, excluding reefer and hazardous containers (R2 L348). Cargo type can change the terminal SOP.
- **[STANDARD]** DCSA only started standardising Shipment Release in 2026 (R1 L7, L100, L1144). There is no standard to adopt yet.
- **Consequence:** Customs Release ≠ Carrier Release / DO ≠ Terminal Readiness ≠ Delivery. Delivery readiness is a combination of gates (R2 L358–365). Owner-confirmed (BD-F09).

### 2.8 Operational closure ≠ financial closure

- **[DESIGN]** No Egyptian rule governs how a forwarder closes a job; both reports recommend separate operational and financial closure (R1 L129; R2 L417–443). Owner-confirmed (BD-F10).

### 2.9 Documents: a readiness profile, not booleans

- **[OFFICIAL-EG]** NAFEZA lists the usual ACI package: commercial invoice, packing list, **copy** of the BL (not the original), certificate of origin, plus cargo-specific certificates (analysis, fumigation, inspection, insurance, health, MSDS, phytosanitary, veterinary) (R1 L133; R2 L129–136).
- **[OFFICIAL-EG]** Each document uploaded to NAFEZA/CargoX is limited to **5 MB**. This is an outbound rule for an ACI submission package, not our storage limit (R1 L133–135; R2 L138).
- **[OFFICIAL-EG]** Importer/exporter identifiers must appear on the commercial invoice and BL, and the ACID on the related shipment documents (R1 L15; R2 L140).
- **[DESIGN]** Model `DocumentRequirement` / `Document` / `DocumentVersion` / `DocumentLink` and a configurable readiness profile, not `has_invoice` booleans (R2 L639–671).
- **Owner position:** the exact Pre-Alert checklist is **not finalised** (BD-P06). The NAFEZA list is evidence for the future profile, not the Pre-Alert rule.

### 2.10 Rules versus configuration

- **[DESIGN]** National Egyptian rules → code/configurable rules. Carrier SOP → carrier profile. Terminal SOP → terminal profile. Company SOP → tenant workflow configuration later. No `AlexandriaWorkflowService` / `DamiettaWorkflowService` (R1 L143–145; R2 L740–751).
- **[OFFICIAL-CARRIER]** Public sources did not provide a stable end-to-end Alexandria or Damietta SOP or reliable clearance durations (R1 L143, L1140; R2 L268, L738). Never hard-code "customs takes X days".
- **[OFFICIAL-CARRIER]** Free time and local charges vary by carrier and change over time (R2 L393, L716). Never hard-code `free_days = 7`; store free time as a sourced, verified value (R2 L726–732).

### 2.11 Architecture and security

- **[DESIGN]** Keep Database-per-Tenant; all FCL transactions in the tenant DB; central DB stays the control plane (R1 L9, L147–184). Owner-confirmed (BD-T01–T03, BD-T10–T12).
- **[DESIGN]** Every queue job is explicitly tenant or central and fails closed without tenant context (R1 L186–196).
- **[DESIGN]** Documents in private, tenant-prefixed storage with a quarantine → scan → promote lifecycle; storage keys never derived from the browser filename; authorization checked before any temporary URL (R1 L198–216, L952–991).
- **[DESIGN]** Do not restore a generic Livewire upload mechanism; every upload request must initialise the tenant and authorise (R1 L993). Matches the M5 decision to disable uploads ([CODE], see [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §5).
- **[DESIGN]** Impersonation stays deny-by-default for FCL writes; customs release, financial approval and document deletion are sensitive (R1 L995; R2 L981). Matches the existing allowlist ([CODE]).
- **[DESIGN]** Livewire and any future API call the same domain actions; never duplicate rules in a component and a controller (R1 L692–702).
- **[DESIGN]** Exact decimal money, explicit currency; never reuse SaaS proration code for shipment charges (R1 L806, L1073).
- **[DESIGN]** External events need idempotency keys / provider event references (R1 L727, L879–890).
- **[DESIGN]** Per-tenant backup and restore drills including documents (R1 L1017).

### 2.12 Standards to design for, not to depend on

| Standard | Use in this product | Source |
|---|---|---|
| UN/LOCODE | canonical port/location identifier where possible | R1 L106 |
| ISO 6346 | container number + check digit + size/type, behind a replaceable validator | R1 L104, L1148 |
| DCSA Booking 2.x / BL 3.x | field meanings for booking and BL; adapter later | R1 L90–93 |
| DCSA Arrival Notice 1.0 | structured arrival notices (M2+) | R1 L94–95 |
| DCSA Track & Trace | event model shape; 3.0 not public until H2 2027, not a dependency | R1 L96–99, L1146 |
| DCSA Shipment Release | not yet standardised; own release domain now | R1 L100 |
| UN/EDIFACT | adapter format only, never the internal schema | R1 L102 |
| IATA Cargo-XML / ONE Record | air cargo; out of scope | R1 L109 |

---

## 3. What the research could not establish

- Vendor tenancy internals and exact pricing are not public (R1 L1136–1138). Not relevant to the build.
- No authoritative Alexandria/Damietta clearance timings or terminal-to-delivery SOP (R1 L1140; R2 L368).
- No public NAFEZA production API contract for filing declarations. The correct MVP is "NAFEZA-aware, not fake-NAFEZA-integrated" (R1 L917, L1142).
- No public SOP for trucking and delivery; this is a field-validation priority (R2 L368–370).
- How the target company actually splits work between Documentation, Operations, Customs, Customer Service and Finance (R1 L1171; R2 L633, L1036–1055). Partly answered by the owner since (BD-P01–P03).

---

## 4. Points raised by the owner that are *not* in the stored reports

The Phase 0 brief mentions two items that do not appear in either stored report (checked by text search on 2026-10-22):

| Item | Where it came from | Status |
|---|---|---|
| ACID format of **19 digits** (owner previously recalled **16**) | Owner's brief, citing research | **Needs source confirmation.** Neither R1 nor R2 states a digit count. No permanent validation rule until an official source is recorded (Q-A01) |
| Official NAFEZA verification services and historical shipping-line **Web Service documentation** | Owner's brief | R2 confirms an "ACI Lock & Request Validator" service for lines and forwarders (R2 L73, L1171). The Web Service documentation itself is not cited in R1/R2. **Needs source and authorization check** (Q-R02). Nothing implies this SaaS is authorised to call a NAFEZA API |

---

## 5. Source verification status

**R1 has no usable citations.** The stored text ends many sentences with a space where a citation marker was removed, and no URL, title or access date survives. Every R1 statement that relies on an external source (DCSA dates, CargoWise Value Packs, NAFEZA requirements, stancl documentation, OWASP, PCI SSC, ClamAV, ISO edition status) must be treated as **needs verification** before it becomes a rule.

**R2 lists its sources by name only** (R2 L1165–1186), stating they were accessed on 22 October 2026, but gives **no URLs**. Each must be located and re-checked before it backs a hard-coded rule:

| # | Source named in R2 | Used for | URL recorded? | Status |
|---|---|---|---|---|
| S1 | Egyptian Customs — Advance Cargo Information | ACI mandatory since 2021-10-01; 48 h before shipping | No | Needs verification |
| S2 | NAFEZA — Documents required to create an ACI file | document package; 5 MB limit | No | Needs verification |
| S3 | NAFEZA — ACI Lock & Request Validator | verify/lock ACID before shipping | No | Needs verification |
| S4 | NAFEZA — Freight Forwarder Role in ACI | foreign FF, Egyptian agent, MBL ACID, HBL handling | No | Needs verification |
| S5 | NAFEZA — Consolidated LCL/FCL ACID | sub-BL ACIDs + master ACID | No | Needs verification |
| S6 | NAFEZA — Import Declaration | declaration as a separate process | No | Needs verification |
| S7 | NAFEZA — Customs Declaration Tracking | declaration status tracking | No | Needs verification |
| S8 | NAFEZA — Import Release | release as a separate process | No | Needs verification |
| S9 | NAFEZA — Submit a Delivery Order | DO as a separate process | No | Needs verification |
| S10 | NAFEZA — Non-Arrived Containers | partial arrival | No | Needs verification |
| S11 | NAFEZA — Overview | documentation → inspection → payment → release | No | Needs verification |
| S12 | CargoX — Egypt ACI / NAFEZA | document transport (vendor corroboration only) | No | Needs verification |
| S13 | Alexandria Container & Cargo Handling — Terminal Operating Systems | CATOS, EDI, OCR gates | No | Needs verification |
| S14 | Alexandria Container & Cargo Handling — Logistics Services Center | inspection/release centre, July 2025 | No | Needs verification |
| S15 | Damietta Container & Cargo Handling | schedules, tracking | No | Needs verification |
| S16 | Hapag-Lloyd Egypt — Local FAQ | DO requirements, lost original BL, free time | No | Needs verification (carrier-specific) |
| S17 | CMA CGM Egypt — Import Information Guide | Arrival Notice, DO, tracking | No | Needs verification (carrier-specific) |
| S18 | Maersk — Container Release | release prerequisites | No | Needs verification (carrier-specific) |

No URL has been added here because none was supplied; inventing one would be worse than leaving the gap. When a source is verified, record the URL, access date and the exact statement relied on in this table.

---

## 6. Conflicts between research and owner decisions

The research findings remain in R1/R2 unchanged. For this project the owner decision is authoritative.

| # | Research says | Owner decided | Architectural effect |
|---|---|---|---|
| X1 | Open a **Draft** job as soon as *actionable* information arrives, even if incomplete; lifecycle `draft → open` (R1 L118, L641–650; R2 L85, L123, L905, L992) | Pre-Alert is expected complete when the job is created; a Draft Import Job is **not** an approved requirement (BD-P03, BD-P08) | M1 has no `draft` job state. If incomplete intake is needed later, it is a separate concept (pre-alert intake / staging), not a weaker Import Job (Q-P03) |
| X2 | ACID "if available" at opening; ACID nullable in the create payload (R1 L117, L744) | **No Import Job without an ACID** (BD-P05) | Job creation requires at least one ACI record in the same transaction |
| X3 | ACI lifecycle managed in the app: issued, verified, locked, amendments (R2 L163–177, L697, L711) | ACID exists **before** the Pre-Alert stage (BD-P04) | We record and reference the ACID. ACI lifecycle fields are optional extensions, not M1 scope (Q-A04) |
| X4 | Pre-Alert received by Customer Service/Documentation and the job opened/received by Operations (R2 L622); scenario: "Origin Agent → Operations/Documentation, Create Draft Job" (R2 L452) | **Documentation** receives the Pre-Alert; **the receiving employee opens the job** (BD-P01, BD-P02) | Job-creation permission is granted to Documentation roles. The creator is recorded immutably |
| X5 | `assigned_user_id` set at creation as the operator (R1 L117, L736) | Creator must be recorded; whether the creator stays the assigned operator is **open** (BD-P07a, BD-P07b, Q-P04) | Separate `created_by` (immutable) from any assignment concept; do not merge them |
| X6 | R1 places `acid_number` on `import_jobs` (R1 L318) | R2 corrects this (R2 L144–179); the owner requires ACID as a proper domain concept (BD-A04) | ACI record entity; R1's single field is rejected |
| X7 | R1's M1 includes documents metadata, tasks and basic charges (R1 L671–686, L1078) and a REST API (R1 L704–721); R2 defers customs, release, delivery and charges (R2 L861–871) | No owner decision on M1 scope beyond these docs | The roadmap here is a **proposal**: documents and charges move out of M1; tasks are minimal or deferred; REST API deferred (see [MVP_ROADMAP.md](MVP_ROADMAP.md)) |
| X8 | Duplicate detection by MBL/HBL/ACID with authorised override (R1 L118) | Owner: do not hard-code one-ACID-per-job; consider consolidated cases (BD-A05) | Duplicate ACID across jobs is a warning/confirmation, not a DB uniqueness rule, until Q-A03 is answered |

---

## 7. What the research recommends next, and how this project treats it

| Recommendation | Source | Treatment |
|---|---|---|
| Complete M5/M5.1 browser verification while FCL starts | R1 L1175; R2 L981 | Agreed as an engineering gate in parallel with FCL M1-A (see [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) §2) |
| Collect 3–5 redacted real FCL import files (origin agent, direct line, multi-container, problem case, consolidated) | R1 L1177; R2 L1057–1093 | Strongly recommended; required before M3/M4 rules, *not* a blocker for M1-A |
| Whiteboard walkthrough with Operations and Customs/Finance | R1 L1179; R2 L1095–1119 | Recommended before freezing the Pre-Alert checklist and customs/release rules |
| Freeze an M1 domain specification before migrations | R1 L1181 | [DOMAIN_MODEL.md](DOMAIN_MODEL.md) + [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) are the draft; owner sign-off pending |
| Implement M1 only; no NAFEZA automation, documents, customs, trucking, accounting or carrier APIs in it | R1 L1183; R2 L754 | Adopted in the roadmap |
