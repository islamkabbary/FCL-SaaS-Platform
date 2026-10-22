# FCL Operations MVP Roadmap (M1–M6)

**Status:** PROPOSED roadmap. The milestone order and scope are recommendations based on R1 L1075–1085, R2 L754–871 and the owner decisions; the owner has not yet approved them. · **Created:** 2026-10-22 (Phase 0)

FCL milestones are named **FCL M1…M6** to avoid confusion with the SaaS milestones 1–5.1. Each FCL milestone follows the project's milestone workflow in the root `CLAUDE.md`:
- documentation under `docs/`;
- verify before committing;
- logical commits on `main`;
- owner identity only;
- no push without authorization.

## Guiding principles

1. **Operational spine first.** M1 proves that one real Egyptian FCL import shipment can be represented correctly, securely and tenant-isolated (R1 L1085–1107; R2 L983–1029).
2. **No regulatory fiction.** NAFEZA-aware, never fake-NAFEZA-integrated (R1 L1142). No invented customs rules, durations or free days.
3. **Open questions block only what depends on them.** Each milestone lists its blocking questions; nothing else waits.
4. **Security parity with the SaaS layer.** Each milestone covers:
   - tenant isolation;
   - branch scope;
   - Livewire action authorization;
   - impersonation denial;
   - subscription enforcement;
   - audit.

   All of these are tested.
5. **Business bugs become regression tests**, each run once without its fix (the M5.1 practice).

## Pre-FCL gate (parallel, not blocking M1-A)

- Owner browser verification of SaaS M5 (doc §18) and M5.1 (doc §9). Automated tests passed. The browser checks are **not done** ([PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) §2.1).
- Owner confirmation of the P0 questions: Q-X01 (code layout) and Q-S03 (tenant audit).

## Overview

| Milestone | Objective | Essential MVP capabilities | Deferred enhancements | Blocking questions | Effort |
|---|---|---|---|---|---|
| **FCL M1** | Core Import Job workspace | FCL permissions, tenant audit/timeline, parties, Import Job with mandatory ACID (ACI records), branch ownership and scope, MBL/HBL, containers with BL links, ETA history, job list and workspace | Documents, tasks automation, charges, booking, API, branch transfer, assignment (unless Q-P04 is answered) | see [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) | Large |
| **FCL M2** | Container lifecycle, secure documents, events, tasks | Per-container events/state (discharge, non-arrival); Arrival Notice facts; tenant-aware secure uploads (quarantine → scan → promote, private tenant-prefixed storage, authorized short-lived downloads); document requirements/readiness profile; basic tasks and triggers; the first queued tenant jobs with isolation tests | OCR/AI extraction, carrier event ingestion, document versions UI polish | Q-DOC01, Q-DOC02, Q-C01, Q-W01, Q-R03 | Large |
| **FCL M3** | Egyptian customs and release gates | Customs declaration records, events, holds/examinations, customs release; carrier release / DO per BL with carrier profile; terminal readiness per container; delivery-readiness derivation; sensitive-action permissions (customs release) | NAFEZA integration (only with a verified authorized contract), ACI lifecycle tracking, amendment workflow automation | Q-W02, Q-W03, Q-W04, Q-A04, Q-A05, Q-B01, Q-S02 | Large |
| **FCL M4** | Delivery, empty return, visibility | Deliveries covering 1..n containers, gate-out, POD (secure document), empty return per container, sourced free-time values and overdue alerts; operational closure; branch transfer (BD-T09) | Customer portal, detention/demurrage engine, trucker portal | Q-W05, Q-W06, Q-W07, Q-BR03 | Medium–Large |
| **FCL M5** | Operational finance and job P&L | Revenue and cost charges per job (exact decimal, explicit currency), vendors as parties, approval permissions, financial closure, job profitability | Accounting/GL, tax invoices, e-invoicing, FX automation, payment gateway | Q-W07, Q-W08, Q-W10 | Large |
| **FCL M6** | Reporting, integrations, production hardening | Operational dashboards and exception reports (branch-scoped); carrier/DCSA adapters that are replay-safe; REST API if needed; per-tenant backup/restore drills including documents; load and security testing | Customer visibility portal, advanced analytics | Q-X04, Q-R02, SaaS production blockers (readiness §5.1) | Large |

## Milestone acceptance gates (summary)

**FCL M1.** One real (anonymised) origin-agent shipment and one direct-line shipment can be entered and read back correctly. In addition:
- No job without an ACID.
- MBL/HBL and BL ↔ container links are correct.
- ETA history is kept.
- Branch scope is enforced.
- Tenant B sees nothing.
- No FCL table in `fcl_central`.
- Every write is audited.
- Impersonated sessions cannot write.
- The owner's browser check is done.

Full criteria: [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) §8.

**FCL M2.**
- Unscanned or infected documents can never be downloaded.
- Signed URLs expire and are issued only after authorization.
- Cross-tenant document access fails through every path.
- Queue jobs keep tenant context on a persistent worker.
- Per-container events are idempotent.

**FCL M3.**
- Customs release, carrier release and terminal readiness are recorded independently.
- Delivery cannot be planned while an applicable gate is open.
- A consolidated ACID scenario can be represented.
- Customs-release actions are permission- and impersonation-protected.

**FCL M4.**
- A delivery covers several containers.
- No pickup before the required gates.
- Empty return is tracked per container.
- Operational closure follows the agreed criteria.
- Branch transfer has a reason, audit and a review of assignments.

**FCL M5.**
- Revenue and cost are separate.
- No floats.
- Duplicate charge submissions are idempotent.
- P&L reconciles.
- SaaS billing code and data are untouched.

**FCL M6.**
- Reporting cannot cross tenant or branch boundaries.
- Integrations are replay-safe.
- A per-tenant restore drill succeeds.
- Remaining production blockers are documented.

## Differences from the research roadmap

| Item | R1's plan | This roadmap | Reason |
|---|---|---|---|
| Draft jobs | M1 supports Draft → Open | No draft jobs | BD-P03, BD-P08 |
| ACID | Single field in M1 | ACI records, mandatory at creation | BD-P05, BD-A04 |
| Documents metadata | M1 | M2, together with secure uploads | Uploads disabled (BD-SEC03); metadata without files invites unsafe shortcuts (PR-12) |
| Charges | M1 basic | M5 | Not needed for the operational spine (PR-12) |
| Booking | M1 optional | Deferred until a case needs more than a reference | BD-F03 |
| REST API | M1 | M6 / when an integration needs it | PR-11 |
| Tasks | M1 basic | M2 (optional minimal list in M1-C) | Assignment model still open (Q-P04) |

## Business discovery that should run alongside M1

These inputs come from R1 L1177–1179 and R2 L1057–1119. They do not block M1-A, but they shape M1-B/C details and M2+:
- 3–5 redacted real files: origin agent, direct line, multi-container, MBL+HBL, and a problem case.
- One walkthrough each with Documentation/Operations and with Customs/Finance. Ask "what did you do next, what blocked you, how did you know it was done?", not "what fields do you need?"
- The current Excel trackers (Q-W09).
