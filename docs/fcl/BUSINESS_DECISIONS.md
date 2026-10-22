# FCL Business Decision Register

**Status:** authoritative register for FCL Operations · **Created:** 2026-10-22 (Phase 0)

## Rules of this register

1. **CONFIRMED** decisions come only from the project owner (Islam Kabbary) or from verified existing code. They override any research recommendation.
2. **PROPOSED** entries are recommendations (by research or Claude) awaiting owner approval. Never implement a PROPOSED entry as if it were confirmed. Where an implementation step needs one, the plan says so explicitly.
3. **OPEN** means undecided. The related question lives in [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md).
4. **DEFERRED** means consciously postponed to a named milestone.
5. An AI recommendation is never labelled as an owner decision. To change a decision, add a new row that supersedes it; do not edit history silently.
6. SaaS-layer decisions (plans, seats, billing, support access) are recorded in the [handover](../FCL_SaaS_Complete_Project_Handover_2026-10-19.md) §2 and §19 and are not repeated here, except where FCL depends on them.

**Sources:** `OWNER-P0` = the owner's Phase 0 brief (2026-10-22) · `HANDOVER` = handover document 2026-10-19 · `CODE` = repository at `bcd7c38` · `R1`/`R2` = research reports (see [RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md)).

---

## 1. Product scope

| ID | Topic | Decision and rationale | Status | Source | Implications | Date |
|---|---|---|---|---|---|---|
| BD-S01 | Product | Multi-tenant SaaS for freight forwarding companies | CONFIRMED | OWNER-P0, HANDOVER §1.1 | Every FCL feature is tenant-scoped | 2026-10-19 |
| BD-S02 | Initial scope | **Egyptian FCL Ocean Import only** | CONFIRMED | OWNER-P0 | No export, air or LCL logic in models, enums or UI | 2026-10-22 |
| BD-S03 | Channels | Support shipments via **Origin Agents** and **Direct Shipping Lines** | CONFIRMED | OWNER-P0 | A source channel per job; the origin agent is optional (BD-F04) | 2026-10-22 |
| BD-S04 | Customs | The target company has an **in-house Customs Clearance team** | CONFIRMED | OWNER-P0 | Customs is an internal workflow (M3), not an outsourced-broker integration | 2026-10-22 |
| BD-S05 | Exclusions | No Air Freight, Export, LCL operations, advanced accounting or unrelated logistics modules in the initial MVP | CONFIRMED | OWNER-P0 | Roadmap M1–M6 stays inside this boundary | 2026-10-22 |
| BD-S06 | Extensibility | Consider future extensibility without adding MVP complexity | CONFIRMED | OWNER-P0 | Leave extension points (types, enums, link tables); do not build speculative features | 2026-10-22 |

## 2. Pre-Alert and Import Job creation

| ID | Topic | Decision and rationale | Status | Source | Implications | Date |
|---|---|---|---|---|---|---|
| BD-P01 | Pre-Alert receipt | The **Documentation Department** receives the Pre-Alert | CONFIRMED | OWNER-P0 | Job-creation permission is intended for Documentation roles. Overrides R2 L622 (X4) | 2026-10-22 |
| BD-P02 | Job opening | The employee who receives the Pre-Alert **opens the Import Job** | CONFIRMED | OWNER-P0 | No hand-off step between receipt and creation in M1 | 2026-10-22 |
| BD-P03 | Completeness | The Pre-Alert is **expected to be complete** when the Import Job is created | CONFIRMED | OWNER-P0 | No `draft` job state in M1. Overrides R1 L118 and R2 L85 (X1). *What* "complete" means is open (BD-P06) | 2026-10-22 |
| BD-P04 | ACID timing | In the target workflow the **ACID exists before the Pre-Alert stage** | CONFIRMED | OWNER-P0 | The app records ACIDs; it does not run ACI filing. Overrides R2's in-app ACI lifecycle (X3) | 2026-10-22 |
| BD-P05 | ACID mandatory | **An Import Job must never be created for a shipment without an ACID** | CONFIRMED | OWNER-P0 | Creation requires ≥ 1 ACI record in the same transaction. Removing the last ACID of a job is refused. Overrides R1 L117, L744 (X2) | 2026-10-22 |
| BD-P06 | Pre-Alert checklist | The precise required Pre-Alert field/document checklist is **not finalised** | OPEN | OWNER-P0 | Do not invent required fields (Q-P01). Only BD-P05 is enforced as a creation rule beyond structural needs | 2026-10-22 |
| BD-P07a | Creator recorded | The employee who created the job **must be recorded** | CONFIRMED | OWNER-P0 | Immutable `created_by` on the job plus an audit entry | 2026-10-22 |
| BD-P07b | Creator as operator | Whether the creator remains the **permanent assigned operator** | OPEN | OWNER-P0 | Keep creator and assignment as separate concepts (Q-P04, X5) | 2026-10-22 |
| BD-P08 | Draft Import Job | A Draft Import Job is **not** an approved requirement; its necessity is open. If incomplete intake is needed later, it may be a concept separate from a formally created Import Job | OPEN | OWNER-P0 | M1 builds no draft jobs (Q-P03) | 2026-10-22 |
| BD-P09 | Parties without ACID | A customer / business party may exist in master data **without** an ACID. The ACID restriction applies to Import Job creation only | CONFIRMED | OWNER-P0 | Parties are master data independent of jobs | 2026-10-22 |

## 3. ACI / ACID

| ID | Topic | Decision and rationale | Status | Source | Implications | Date |
|---|---|---|---|---|---|---|
| BD-A01 | Mandatory | ACID is mandatory before Import Job creation (= BD-P05) | CONFIRMED | OWNER-P0 | — | 2026-10-22 |
| BD-A02 | Format | Exact ACID format (19 digits per research cited by the owner; owner recalled 16) **needs source confirmation** before a permanent validation rule | OPEN | OWNER-P0 | Store as a string. Strict format validation waits for Q-A01. Neither stored report states a digit count ([RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md) §4) | 2026-10-22 |
| BD-A03 | Verification | Official NAFEZA verification services and historical shipping-line Web Service documentation have been identified, but the SaaS has **no** authorization to consume a NAFEZA API. **Format validation ≠ authoritative external verification** | CONFIRMED | OWNER-P0 | UI and code must never call a format check "verified". No NAFEZA integration (Q-R02) | 2026-10-22 |
| BD-A04 | Domain concept | ACI/ACID is a **proper domain concept**, not permanently a single unstructured job field | CONFIRMED | OWNER-P0; supported by R2 L144–179 | An ACI record entity linked to the job and optionally to a BL. Rejects R1's `import_jobs.acid_number` (X6) | 2026-10-22 |
| BD-A05 | Uniqueness | Do **not** hard-code one-ACID-per-job, and consider MBL/HBL and consolidated cases before any uniqueness rule | CONFIRMED | OWNER-P0; R2 L543–559 | A job may hold several ACI records; cross-job duplicates are detected, not DB-constrained (Q-A03) | 2026-10-22 |

## 4. Freight domain

| ID | Topic | Decision and rationale | Status | Source | Implications | Date |
|---|---|---|---|---|---|---|
| BD-F01 | Workspace | The **Import Job** is the main operational workspace | CONFIRMED | OWNER-P0; R1 L3 | Job page is the hub; other entities hang off it | 2026-10-22 |
| BD-F02 | No giant table | The Import Job must **not** become one giant table | CONFIRMED | OWNER-P0; R1 L1165 | Separate tables for BLs, ACI records, containers, events, etc. | 2026-10-22 |
| BD-F03 | Booking | Booking is **not mandatory** for a destination FCL Import Job | CONFIRMED | OWNER-P0; R1 L122 | Booking is an optional reference; not built in M1 | 2026-10-22 |
| BD-F04 | Origin agent | The Origin Agent is **not mandatory** for Direct Shipping Line cases | CONFIRMED | OWNER-P0; R2 L523 | Origin agent party role optional | 2026-10-22 |
| BD-F05 | Distinct concepts | MBL, HBL, Containers, Documents, Events, Customs, Release, Delivery and Charges are **distinct concepts** | CONFIRMED | OWNER-P0 | Separate entities and state, linked to the job | 2026-10-22 |
| BD-F06 | No giant status | The Import Job must not become **one giant status machine** | CONFIRMED | OWNER-P0; R1 L641; R2 L62 | Coarse job lifecycle + per-domain state + derived readiness | 2026-10-22 |
| BD-F07 | BL ↔ container | Do not assume 1:1 relationships between BLs and containers | CONFIRMED | OWNER-P0; R1 L667 | Many-to-many link | 2026-10-22 |
| BD-F08 | Container lifecycle | Container lifecycle is **independent per container** | CONFIRMED | OWNER-P0; R2 L251–264 | Per-container state, events, release and return | 2026-10-22 |
| BD-F09 | Release processes | **Customs Release, Carrier Release, Terminal Readiness and Delivery** are separate processes | CONFIRMED | OWNER-P0; R2 L77, L358 | Separate gates; delivery readiness is derived | 2026-10-22 |
| BD-F10 | Closure | **Operational Closure and Financial Closure** are distinct | CONFIRMED | OWNER-P0; R2 L441 | Two separate timestamps / states; delivered ≠ closed | 2026-10-22 |

## 5. Multi-tenancy and branches

| ID | Topic | Decision and rationale | Status | Source | Implications | Date |
|---|---|---|---|---|---|---|
| BD-T01 | Architecture | Laravel **monolith**, organised by feature (owner term: modular monolith) | CONFIRMED | OWNER-P0; CODE | Today's code is grouped by technical type with feature subfolders ([PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §4.1). Exact FCL folder layout: Q-X01 | 2026-10-22 |
| BD-T02 | Tenancy | **Database per tenant** | CONFIRMED | HANDOVER §2.1; CODE | FCL tables in `database/migrations/tenant/` | 2026-10-19 |
| BD-T03 | Control plane | The central SaaS/control plane stays separate from tenant operations | CONFIRMED | OWNER-P0; CODE | No FCL model uses `CentralConnection` | 2026-10-22 |
| BD-T04 | Tenant | Each freight forwarding company is one tenant | CONFIRMED | HANDOVER §2.1 | — | 2026-10-19 |
| BD-T05 | Branches | Branches exist **inside** the tenant | CONFIRMED | HANDOVER §2.1; CODE (`branches` table) | Branch scoping is authorization, not DB isolation | 2026-10-19 |
| BD-T06 | Job branch | Import Jobs belong to **one primary branch** | CONFIRMED | OWNER-P0; HANDOVER §2.2 | Required `branch_id` on the job | 2026-10-22 |
| BD-T07 | Visibility | Branch visibility is **permission-based** | CONFIRMED | OWNER-P0 | Scopes defined by permissions (Q-BR01) | 2026-10-22 |
| BD-T08 | Cross-branch | Cross-branch work must be possible under the appropriate authorization model | CONFIRMED | OWNER-P0; HANDOVER §2.2 | Model must not assume users only touch their own branch | 2026-10-22 |
| BD-T09 | Branch change | Changing a job's primary branch eventually requires **special authorization, a reason, audit information, and review of assignments and open tasks** | CONFIRMED (requirement) / DEFERRED (build) | OWNER-P0; HANDOVER §2.2 | Not in M1; the primary branch is not editable in the M1 UI (Q-BR03) | 2026-10-22 |
| BD-T10 | Tenant data | **All FCL operational transaction data belongs in the tenant database** | CONFIRMED | OWNER-P0 | Includes the FCL audit/timeline | 2026-10-22 |
| BD-T11 | Central data | SaaS plans, subscription fees, seats and platform billing stay in the central database | CONFIRMED | OWNER-P0; CODE | — | 2026-10-22 |
| BD-T12 | Two kinds of money | Shipment costs, customer charges, vendor costs and job profitability are **conceptually separate** from SaaS billing | CONFIRMED | OWNER-P0 | No reuse of `app/Billing` domain code for shipment finance (M5) | 2026-10-22 |

## 6. Security

| ID | Topic | Decision and rationale | Status | Source | Implications | Date |
|---|---|---|---|---|---|---|
| BD-SEC01 | Impersonation | New FCL **writes are not allowed during impersonation** unless explicitly reviewed | CONFIRMED | OWNER-P0; CODE (`RestrictionPolicy` allowlist) | Do not add FCL methods/components to the allowlist without owner review. FCL **reads** during impersonation: Q-S01 | 2026-10-22 |
| BD-SEC02 | Default deny | New FCL permissions and actions are **denied by default** until explicitly reviewed | CONFIRMED | OWNER-P0 | Employee role gets no FCL permissions automatically; Tenant Admin receives them via sync ([PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §5.5) | 2026-10-22 |
| BD-SEC03 | Uploads | Generic Livewire uploads stay **disabled**; tenant-aware uploads are a dependency of the document milestone | CONFIRMED | OWNER-P0; CODE (`RejectTemporaryUploads`) | No `WithFileUploads` in FCL until M2's secure design | 2026-10-22 |

## 7. Knowledge governance

| ID | Topic | Decision and rationale | Status | Source | Implications | Date |
|---|---|---|---|---|---|---|
| BD-G01 | Precedence | Confirmed owner decisions override research suggestions | CONFIRMED | OWNER-P0 | See conflicts X1–X8 in [RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md) §6 | 2026-10-22 |
| BD-G02 | Code authority | The repository's current code is the authority for implemented behaviour; milestone reports are historical evidence | CONFIRMED | OWNER-P0 | Re-verify before relying on a report | 2026-10-22 |
| BD-G03 | Open ≠ approved | Open questions and proposals are not approved requirements | CONFIRMED | OWNER-P0 | — | 2026-10-22 |
| BD-G04 | Research preserved | The two research reports stay unchanged as historical sources | CONFIRMED | OWNER-P0 | Corrections go into the synthesis, never into the reports | 2026-10-22 |

---

## 8. Proposals awaiting owner approval

These are Claude's Phase 0 recommendations. They are **not** decisions. Approve, change or reject each one; the M1 plan marks which steps depend on which proposal.

| ID | Proposal | Why | Depends on / answers | Status |
|---|---|---|---|---|
| PR-01 | Job lifecycle in M1: `open → operationally_complete → closed`, plus `cancelled`; **no `draft`** and no `on_hold` until needed | Honors BD-P03/P08; keeps the coarse lifecycle small | BD-F06, BD-F10 | PROPOSED |
| PR-02 | `operationally_completed_at` and `financially_closed_at` as two independent facts on the job, rather than one closed state | BD-F10 | Q-W07 | PROPOSED |
| PR-03 | Creation requires: ≥ 1 ACI record (BD-P05), primary branch, source channel (origin agent / direct shipping line), customer party. All else optional | Minimum that makes a job meaningful without inventing a checklist | Q-P01, Q-D01 | PROPOSED |
| PR-04 | ACI record entity: `acid` string, optional link to one BL, notes; no lifecycle timestamps in M1 | BD-A04/A05; X3 | Q-A02, Q-A04 | PROPOSED |
| PR-05 | Cross-job duplicate ACID / MBL detection with explicit confirmation, no DB uniqueness | BD-A05 | Q-A03, Q-B03 | PROPOSED |
| PR-06 | Tenant-wide job numbering `IMP-<year>-<6-digit sequence>`, generated under lock in the creation transaction | Simple, unique, readable | Q-P05 | PROPOSED |
| PR-07 | Parties: one tenant-wide `parties` table + per-job role links; role enum starting with customer, importer, consignee, notify, shipper/exporter, origin agent, carrier | R1 L669; BD-P09 | Q-D01 | PROPOSED |
| PR-08 | FCL ability names are FCL-specific (`fcl.jobs.view`, …) so that FCL reads are denied during impersonation until reviewed | Closes the `viewAny`/`view` gap ([PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §5.4) | Q-S01 | PROPOSED |
| PR-09 | New append-only tenant audit (`fcl` scope first) written explicitly by actions; the job timeline reads from audit + domain events | No tenant audit exists | Q-S03 | PROPOSED |
| PR-10 | FCL code follows the existing feature-subfolder convention; no new base folder | AGENTS.md | Q-X01 | PROPOSED |
| PR-11 | Livewire only in the MVP; REST API deferred, but all rules in actions | R1 L692–702 | Q-X04 | PROPOSED |
| PR-12 | Documents, charges and booking are **not** in M1 (unlike R1's M1 list) | Keeps M1 to the operational spine (R1 L1183) | X7 | PROPOSED |
| PR-13 | Two branch visibility permissions to start (own branch / all branches) | BD-T07, BD-T08 | Q-BR01 | PROPOSED |
