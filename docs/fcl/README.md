# FCL Operations — Knowledge Base

This folder is the source of truth for the **FCL Ocean Import Operations** module of the FCL SaaS Platform. The SaaS management layer (Milestones 1–5.1) is documented one level up in [`docs/`](../).

**Current stage:** FCL M1-A1 to A3 done (permissions, impersonation denial, tenant audit; 2026-10-22). **Resume from [HANDOVER.md](HANDOVER.md).** Progress log: [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md).

## Authority order

When documents disagree, trust them in this order:

1. **The repository's current code**, for anything already implemented.
2. **Owner decisions** marked CONFIRMED in [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md).
3. This knowledge base's proposals (PROPOSED). These are not approved.
4. The research reports. They are historical input, overridden by 1 and 2 where they conflict.

Open questions are **not** requirements. Do not implement a provisional approach as if it were approved.

## Documents

| File | Purpose | Read when |
|---|---|---|
| [HANDOVER.md](HANDOVER.md) | Resume point: current phase, what is delivered, verification, open decisions, next batch, session instructions | First, in a new session or on a new computer |
| [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) | Vision, scope, users, the **code-verified** SaaS baseline (tenancy, auth, impersonation, audit, uploads, tests) and the constraints FCL inherits | Before any FCL work |
| [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md) | Decision register: CONFIRMED / PROPOSED / OPEN / DEFERRED, with IDs (BD-xx, PR-xx) | Before designing or changing behaviour |
| [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) | Decision backlog (Q-xx) with what each question blocks and a provisional approach | Before starting a step; when the owner answers something |
| [RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md) | What the two studies mean for this product, evidence levels, source-verification status, research-vs-owner conflicts | When you need the "why" behind a design rule |
| [OPERATIONAL_WORKFLOW.md](OPERATIONAL_WORKFLOW.md) | End-to-end Egyptian FCL import workflow, responsibilities, variations, release gates, exceptions | When building any operational feature |
| [DOMAIN_MODEL.md](DOMAIN_MODEL.md) | Conceptual entities, cardinalities, invariants, dangerous assumptions, extension points | Before writing migrations or models |
| [MVP_ROADMAP.md](MVP_ROADMAP.md) | FCL M1–M6 scope, gates and blocking questions | Planning a milestone |
| [M1_IMPLEMENTATION_PLAN.md](M1_IMPLEMENTATION_PLAN.md) | M1-A / M1-B / M1-C deliverables, tests, risks, acceptance criteria | Implementing M1 |
| [research/01-global-fcl-research.md](research/01-global-fcl-research.md) | **Original** study R1: global forwarding software, standards, architecture (unchanged) | Source reference |
| [research/02-egypt-operations-research.md](research/02-egypt-operations-research.md) | **Original** study R2: Egyptian FCL import operations, NAFEZA, ACI/ACID (Arabic, unchanged) | Source reference |

## Maintenance rules

- Never edit the files in `research/`. Record corrections and verified sources in [RESEARCH_SYNTHESIS.md](RESEARCH_SYNTHESIS.md) §5.
- When the owner answers a question:
  1. add or update the decision in [BUSINESS_DECISIONS.md](BUSINESS_DECISIONS.md);
  2. mark the question `RESOLVED → BD-xx` in [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md);
  3. adjust the plan if needed.
- Each FCL milestone still gets its own milestone report under `docs/`, as the root `CLAUDE.md` requires. For example `docs/FCL_M1_IMPORT_JOB_WORKSPACE.md`.
- Keep this folder small. Update an existing document rather than adding a near-duplicate.
