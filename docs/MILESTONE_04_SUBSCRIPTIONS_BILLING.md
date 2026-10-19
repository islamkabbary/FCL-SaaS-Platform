# Milestone 4 — SaaS Subscriptions, Seats & Billing

This document describes what was **actually implemented and verified** in Milestone 4. It builds on [Milestone 1](MILESTONE_01_SAAS_FOUNDATION.md), [Milestone 2](MILESTONE_02_TENANT_USERS_PERMISSIONS.md) and [Milestone 3](MILESTONE_03_SAAS_PLATFORM_MANAGEMENT.md).

> **Important:** no commercial values are configured. Plan prices and currencies are entered by a Platform Admin. Pricing, currency, tax, invoicing and payment-collection policies remain **open business decisions**. Where this milestone needed a rule that had not been agreed, it uses a minimal technical default, listed in §3.2.

---

## 1. Milestone Overview

**Goal (met):** a manually operated, annual-only subscription and seat-billing MVP:
- Platform Admins configure fixed plans, activate and renew subscriptions, review seat requests and record payments.
- Seat limits are enforced on employee management.
- Tenant Admins see their subscription and billing history and request seat changes.

| Acceptance criterion | Status |
|---|---|
| 1. Fixed annual plans configurable | Done |
| 2. Manual annual subscription activation | Done |
| 3. Base fee + seats × seat price calculated correctly | Done (exact integer arithmetic) |
| 4. Tenant Admin sees purchased and available seats | Done |
| 5. Employee creation respects seats | Done (server-side, concurrency-safe) |
| 6. Deactivated seats reusable without extra billing | Done |
| 7. Tenant Admin can request more seats | Done |
| 8. Platform Admin approves or rejects seat requests | Done |
| 9. Accurate prorated charges for mid-term seats | Done |
| 10. Reductions take effect only at renewal | Done |
| 11. Renewals preserve pricing and billing history | Done |
| 12. Manual billing and payments consistent | Done |
| 13. Commercial data isolated and authorized | Done |
| 14. Suspension and provisioning still independent | Done |
| 15. Previous milestone tests pass | Done (166 earlier tests unchanged in behavior) |
| 16–18. Documentation, commits on `main`, nothing pushed | Done |

---

## 2. Features Implemented

**Central platform** (`/admin`, Platform Admins only):
- **Plans** (`/admin/plans`):
  - list, create and edit (name, description, annual base fee, annual seat price);
  - the currency is chosen on creation and cannot be changed afterwards;
  - plans can be archived and reactivated.
- **Subscriptions** (`/admin/subscriptions`):
  - every tenant with its subscription state, plan, seats and renewal date;
  - filters: active, renewal due or overdue, expired, none, legacy.
- **Subscription details** (`/admin/tenants/{id}/subscription`):
  - status, alongside the provisioning and access badges;
  - purchased seats, active users (read from the tenant database) and the outstanding balance;
  - the current term with its effective dates, next renewal and price snapshot;
  - any scheduled reduction, the term history, charges and seat requests.
- **Activation** (`…/subscription/activate`): choose a plan, seats and start date, then preview the term dates, active users and annual charge, then confirm.
- **Renewal** (`…/subscription/renew`):
  - a preview of the new term dates, seats (with any approved reduction), active users and annual charge;
  - price or plan changes are highlighted and must be explicitly confirmed;
  - blocking problems are listed.
- **Seat requests** (`/admin/seat-requests`): pending requests with the exact charge an approval *today* would create, approve or reject with an optional note, and recently reviewed requests.
- **Billing** (`/admin/charges`): all or outstanding charges. Each charge page shows lines, payments and balance, plus a manual payment form.
- **Dashboard:** active subscriptions, renewals due within 30 days, expired subscriptions, pending seat requests and outstanding totals per currency, all from real records.

**Tenant application:**
- **Subscription & billing** (`/subscription`, requires `billing.view`):
  - plan, term dates, renewal date and snapshot prices;
  - purchased, used and available seats, with a warning if over the limit;
  - an approved reduction, if any;
  - seat-request history, billing history (lines and payments) and term history.
- With `billing.manage`: request additional seats (with a live prorated estimate), request fewer seats for the next renewal, and cancel a pending request.
- **Employees page** shows "X of Y purchased seats in use · Z available" and a callout when none are available.
- **Subscription required** page (`/subscription-required`) is shown when the application is restricted.
- New tenant permissions `billing.view` and `billing.manage`, held by the built-in Tenant Admin role. Regular employees have neither unless granted through a custom role.

---

## 3. Business Rules

### 3.1 Confirmed business rules (from the milestone brief)
- One tenant is one company with its own database. There are fixed plans only, billed **annually only**, with no self-registration and manual management by Platform Admins. All features are included, with no shipment limits and no add-ons.
- **Annual Subscription Total = Annual Base Fee + (Purchased Seats × Annual Price Per Seat)**. Both prices are configured per plan, never hard-coded.
- The currency is explicitly configured per plan. Calculations across currencies are prevented.
- Seats:
  - every **active** user consumes a seat, including Tenant Admins;
  - inactive users do not;
  - deactivating frees a seat for reuse at no extra charge.
- Additional seats mid-term are requested by a Tenant Admin, approved by a Platform Admin, and **charged prorated until the current renewal date**.
- Reductions do not reduce the current commitment. Approved reductions apply at the **next annual renewal**. There are no automatic refunds.

### 3.2 Technical defaults chosen in this milestone (not business decisions)
| Topic | Default | Where |
|---|---|---|
| Money representation | Integer minor units plus an ISO 4217 code. No floats. | `App\Billing\Money` |
| Supported currencies | AED, BHD, EGP, EUR, GBP, JOD, KWD, OMR, QAR, SAR, USD, with their ISO minor-unit precision (2 or 3 decimals). This is a technical allow-list, not a choice of currency. | `config/billing.php` |
| Rounding | Prorated amounts are rounded **once, half up, to the minor unit**. Annual totals need no rounding. | `App\Billing\Pricing` |
| Term dates | Half-open `[starts_on, ends_on)`, where `ends_on` = start + 1 calendar year without overflow. A 29 February start ends on 28 February. Dates use the app timezone (UTC). | `Pricing::annualTermEnd` |
| Activation start date | Today or in the past, and the term must not already be over. A backdated start is charged the full annual amount. | `ActivateSubscription` |
| Renewal window | Opens 30 days before the term ends (`billing.renewal_window_days`). | `config/billing.php` |
| Late renewal | Confirmed after expiry, the new term starts on the **confirmation day**, so the lapsed period is not billed. Confirmed on time, it starts on the previous renewal date. | `RenewSubscription` |
| Access without an active subscription | Users can sign in and use settings and the subscription pages. All other tenant functionality is blocked. No grace period. | §14 |
| Pending requests | At most one pending seat request per tenant. The last approved reduction wins. | DB unique key |
| Seat increase after an early renewal | Seats are added to the current term (prorated) **and** to the already-confirmed next term (full term, at its snapshot price), as two lines of one charge. | `ReviewSeatChangeRequest` |
| Proration date | The estimate uses the request day. The **charge** uses the approval day. | §10 |
| Payments | Recorded against one charge. Partial payments are allowed; overpayment is not. No refunds or credits. | `RecordPayment` |
| Max seats / price | 10,000 seats; 100,000,000,000 minor units per price (sanity limits). | `config/billing.php` |
| Existing tenants | "Legacy": unrestricted until a subscription is activated. | §17 |

---

## 4. Subscription Architecture

- **All commercial data is central.** Plans, subscriptions, terms, seat requests, charges, lines, payments and audit records live in the central database. Every model uses `CentralConnection`, so it reads the central database even inside tenant context, for example from the tenant subscription page.
- **Tenant databases are unchanged.** Users, roles, permissions and branches stay there. No employee data is copied centrally. Seat usage is counted **live** from the tenant's `users` table (`App\Billing\ActiveUsers` from the central side, `SeatGuard::usage()` in tenant context).
- **Cross-database consistency:** no transaction spans both connections, so each workflow is designed explicitly.
  - **Employee creation and reactivation** (tenant DB write, central entitlement) runs under a **per-tenant MySQL named lock** (`GET_LOCK('fcl_seats_<tenant id>')` on the central connection). Under the lock it:
    1. re-reads the entitlement from the central database;
    2. counts active users in the tenant database;
    3. commits the tenant write;
    4. releases the lock.

    Named locks are server-wide and independent of transactions, so concurrent requests from any PHP process are serialized. A lock that is not obtained within 5 seconds yields a "try again" error.
  - **Activation and renewal** (central writes, tenant user count): the active-user count is read from the tenant database first, as a point-in-time value. The central writes then happen in one central transaction with row locks. The only possible discrepancy is a user activated between the count and the commit. It is then *over limit* but never deactivated. The tenant UI shows an "over limit" warning and blocks further activations until resolved. That is the reconciliation path.
  - **Seat requests** are written centrally from tenant context, with the tenant taken from `tenant()`, never from input. The requester's tenant user id, name and email are copied, because there is no cross-database foreign key.
- **Idempotency:**
  - charges carry a unique `idempotency_key` (`term:<id>`, `seat-request:<id>`);
  - terms are unique per (subscription, sequence) and (subscription, starts_on);
  - pending requests are unique per tenant (`pending_tenant_id`);
  - payments carry a unique form `submission_token`;
  - all state checks run under row locks.
- **UI:** the central pages are controllers with Blade and Flux, as in Milestone 3, because Livewire's update endpoint is tenant-only. The tenant subscription page is a Livewire component, like the Milestone 2 pages.

---

## 5. Central Database Schema

All migrations are additive. Amounts are `unsigned bigint` minor units.

| Table | Key columns |
|---|---|
| `subscription_plans` | `name` (unique), `description`, `currency` char(3), `annual_base_fee`, `annual_seat_price`, `is_active` |
| `tenant_subscriptions` | `tenant_id` (**unique**, FK restrict), `subscription_plan_id` (plan of the latest term), `currency` (fixed for life), `activated_at`, `activated_by` |
| `subscription_terms` | `tenant_subscription_id`, `tenant_id`, `sequence`, `kind` (initial or renewal), `subscription_plan_id`, **snapshot:** `plan_name`, `currency`, `annual_base_fee`, `annual_seat_price`, `initial_seats`; `seats` (current entitlement), `starts_on`, `ends_on`, `created_by`. Unique (subscription, sequence) and (subscription, starts_on). |
| `seat_change_requests` | `tenant_id`, `tenant_subscription_id`, `subscription_term_id`, `type`, `seats`, `status`, `pending_tenant_id` (**unique**, set only while pending), requester id/name/email, `request_note`, `currency`, `estimated_amount`, `reviewed_by`, `reviewed_at`, `review_note`, `billing_charge_id` (unique), `applied_to_term_id` |
| `billing_charges` | `tenant_id`, `tenant_subscription_id`, `subscription_term_id`, `type`, `currency`, `amount`, `description`, `idempotency_key` (**unique**), `issued_on`, `created_by` |
| `billing_charge_lines` | `billing_charge_id`, `description`, `quantity`, `unit_amount`, `amount`, `calculation` (JSON inputs, e.g. remaining and total days) |
| `billing_payments` | `billing_charge_id`, `tenant_id`, `currency`, `amount`, `paid_on`, `reference` (≤100 chars), `note`, `submission_token` (**unique**), `recorded_by` |
| `tenants` (+1 column) | `subscription_required` boolean, default true. **Existing rows were set to false** (legacy). |
| `platform_audit_logs` (+2 columns, 1 change) | `actor_type` (`platform_admin` or `tenant_user`, default `platform_admin`), `tenant_user_id`; `tenant_id` now nullable (plan actions are platform-wide) |

Foreign keys to tenants, plans, terms and charges are `restrict`, so financial history cannot be deleted through cascades. Admin references are `set null`.

---

## 6. Plans and Pricing Configuration

- Prices are entered as decimals in the plan currency, for example `1250.50`. The input is validated against the currency's precision: `10.123` is rejected for USD, while `0.001` is accepted for KWD. Values are converted exactly to minor units with brick/math.
- **A price change never affects existing terms or charges.** Every term stores its own snapshot (tested), and editing a plan only affects terms created afterwards.
- **The currency cannot be changed** after creation (validation rejects it), so plan prices are always comparable with the terms priced from them.
- **Archiving** hides a plan from new activations and renewals. Subscriptions priced from it stay valid (tested). Plans are never deleted.
- Annual billing is the only option, so there is no interval field.

---

## 7. Subscription Lifecycle

`App\Enums\SubscriptionStatus` is **derived, never stored**. It is computed from the central records on each check:

| Status | Condition | Access | Seat limit |
|---|---|---|---|
| **Legacy** | No subscription and `subscription_required = false` (created before M4) | Full, as before | Unlimited |
| **None** | No subscription and `subscription_required = true` (every tenant created from now on) | Restricted (§14) | 0 |
| **Active** | A term covers today: `starts_on ≤ today < ends_on` | Full | That term's `seats` |
| **Expired** | Has a subscription, no term covers today | Restricted (§14) | 0 |

- **Transitions:**
  - Legacy or None → Active through **Activate**. Activation also sets `subscription_required = true`, so a legacy tenant cannot fall back to legacy.
  - Active → Expired automatically when the date passes; no job is needed.
  - Expired → Active through **Renew**.
- **Dates:** the start is inclusive and `ends_on` (the renewal date) is **exclusive**. The UI shows "1 Mar 2026 to 28 Feb 2027 (inclusive), renews on 1 Mar 2027". On 1 Mar 2027 the term no longer applies (tested at the boundary).
- **Independence:** subscription actions never write the provisioning `status`, `suspended_at` or `suspension_reason` (tested). Provisioning and suspension never write subscription data.

---

## 8. Seat Allocation and Enforcement

- **Seats in use** = active users in the tenant database: the Tenant Admin, other admins and employees (`users.is_active = 1`).
- **Creation** (`CreateEmployee`) and **reactivation** (`SetEmployeeActivation`) go through `SeatGuard::claim()`:
  1. legacy tenants pass, with no limit;
  2. otherwise the per-tenant named lock is taken;
  3. the limit is re-read from the term covering today;
  4. active users are counted;
  5. if none are available, `NoSeatAvailable` is thrown with a clear message;
  6. the write is committed and the lock released.
- **Deactivation** is unchanged from Milestone 2: the user loses access, sessions are deleted and the remember token rotated. The entitlement and billing are untouched, and the freed seat can be reused by a new or reactivated user with no new charge (tested).
- **Concurrency:**
  - a test holds the tenant's lock from a second MySQL session and confirms creation waits and then gives up;
  - a test asserts the query order (lock → count → insert → release);
  - the real-server check raced **6 separate PHP processes** for 2 free seats: exactly 2 were created and 4 refused.
- Unchanged Milestone 2 rules: authorization, privilege rules, last-active-admin protection, permission cache isolation.

---

## 9. Seat Increase Requests

1. A Tenant Admin (`billing.manage`) enters the number of additional seats on `/subscription`. A live estimate is shown, computed server-side from the term snapshot price; the browser never supplies a price.
2. `RequestSeatChange` validates the request:
   - an active term is required;
   - at least 1 seat;
   - the total cannot exceed the maximum;
   - no other request may be pending (unique key).

   It then stores the request centrally with the tenant id from context, the requester identity and the estimate, and audits it as a **tenant-user** action.
3. The Platform Admin sees it at `/admin/seat-requests` with the **exact charge if approved today**.
4. **Approve** (`ReviewSeatChangeRequest::approve`):
   1. locks and re-checks that the request is still *pending*;
   2. locks the subscription;
   3. requires a term covering today;
   4. adds the seats to that term, sharing its renewal date and not restarting the year, and to an already-renewed next term if any;
   5. creates the `seat_increase` charge with key `seat-request:<id>`;
   6. records the reviewer and time, and audits the action.
5. **Reject** marks the request rejected with an optional note. Seats and billing are unchanged.
6. The Tenant Admin sees the status, note and new seat availability. A pending request can be cancelled.

Duplicate approvals fail with "already approved", and the idempotency key makes a second charge impossible (tested over HTTP and directly). Tenant users have no route or permission to approve.

---

## 10. Proration Calculation

```
Prorated charge = round_half_up( seats × annual_seat_price × remaining_days / total_days )
remaining_days = days from the approval date (inclusive) to ends_on (exclusive)
total_days     = days from starts_on to ends_on (365 or 366)
```

The calculation is exact (`BigRational`), rounded once to the minor unit. It is only possible on a day inside the term: approving on or after `ends_on` is refused. The amount is never negative. Each charge line stores its inputs (`remaining_days`, `total_days`, `calculated_on`, `annual_seat_price`, `seats`, `term_id`, `rounding`).

**Worked examples** (USD, seat price 120.00 = 12,000 minor units). Each one is a test.

| Term | Approval date | Seats | Remaining / total | Calculation | Charge |
|---|---|---|---|---|---|
| 2026-03-01 → 2027-03-01 | 2026-03-01 (first day) | 3 | 365 / 365 | 3 × 12000 × 365/365 | **360.00** |
| 2026-03-01 → 2027-03-01 | 2026-12-01 | 3 | 90 / 365 | 3 × 12000 × 90/365 = 8,876.71… | **88.77** |
| 2026-03-01 → 2027-03-01 | 2027-02-28 (last day) | 1 | 1 / 365 | 12000/365 = 32.88 | **0.33** |
| 2028-01-01 → 2029-01-01 (leap) | 2028-07-01 | 2 | 184 / 366 | 2 × 36600 × 184/366 | **368.00** (seat price 366.00) |
| 2028-01-01 → 2029-01-01 (leap) | 2028-12-31 | 1 | 1 / 366 | 183/366 = 0.5 exactly | **0.01** (half rounds up, seat price 1.83) |

Amounts in the calculation column are in minor units (cents); 8,876.71… rounds half up to 8,877 = 88.77 USD.

**After an early renewal** (term 1 ends 2027-01-15, term 2 already confirmed, approval on 2027-01-05, 2 seats): one charge with two lines. The first is `2 × 12000 × 10/365 = 657.53 → 6.58`; the second is the full renewed term, `2 × 120.00 = 240.00`. The total is **246.58**.

---

## 11. Seat Reductions

- A Tenant Admin requests a **seat count for the next term**, which must be between 1 and the current seats − 1.
- Approval changes nothing now: the current term's seats, prices and charges stay as they are (tested). The request becomes "Approved", shown as *scheduled* on both sides.
- A later approved reduction **supersedes** an earlier one. Only one request can be pending at a time.
- At renewal the scheduled reduction becomes the default seat count, is marked **Applied** and is linked to the new term (`applied_to_term_id`).
- **No user is ever deactivated automatically.** If active users exceed the reduced count, the renewal **cannot be confirmed**. The preview shows "N users are active, more than the M seats proposed…". The Platform Admin adjusts the seat count, or the tenant deactivates users first (tested).
- There are no refunds or credits.

---

## 12. Annual Renewals

- **Due list:** the dashboard card and `/admin/subscriptions?status=renewal_due` show subscriptions whose latest term ends within 30 days or has already ended.
- **Preview:**
  - the new term dates and seats, with any applied reduction;
  - active users;
  - the plan (same-currency active plans only) and the new annual charge;
  - if the plan or prices differ from the expiring term's snapshot, a warning with old versus new prices. The admin must tick "I confirm the new plan and prices", which is verified server-side.
- **Confirm** (`RenewSubscription`), under a lock on the subscription:
  - creates term `sequence + 1` with a fresh price snapshot;
  - creates a `renewal` charge (`term:<id>`);
  - updates the subscription's plan;
  - applies the scheduled reduction;
  - audits the renewal with old and new plan, prices and seats.
- **History is preserved:** earlier terms and charges are never modified (enforced by the models and tested).
- **No duplicates:** after a renewal the latest term ends a year later, so the window is closed and a second submission is refused. The unique keys back this up (tested).
- **Rejected:** a renewal before the window opens, to an archived plan, to a plan in another currency, or below the active-user count.
- **Expired subscriptions:** see §3.2. Renewing restores access immediately and never touches manual suspension.

---

## 13. Manual Billing and Payments

- **Charge types:** `initial` (activation), `renewal`, and `seat_increase` (prorated).
  - Annual charges have two lines: the base fee (1 × base) and the seats (N × seat price).
  - Each line stores quantity, unit amount, amount and calculation inputs.
  - Charges are internal commercial records, **not tax invoices**. There is no VAT, numbering or e-invoicing.
- **Status is derived** from the payments: Outstanding, Partially paid or Paid. No stored status can drift from the payments.
- **Recording a payment** (charge page, Platform Admin):
  - amount, date (today or earlier), optional non-sensitive reference and note;
  - the charge row is locked, the balance recomputed, and the amount must be greater than 0 and no more than the balance;
  - a double submission of the same form records one payment (`submission_token`);
  - each payment is audited with the balance before and after.
- **Append-only:** charges, lines and payments throw on update or delete. Term snapshots and dates cannot change. There are no delete routes.
- **Tenant view:** read-only billing history on `/subscription`. Tenants cannot mark charges as paid: there is no route on the tenant domain, and `/admin/...` returns 404 there (tested).
- No gateways, checkout, stored cards, tax engines, conversions, ledgers, refunds or credit notes.

---

## 14. Access Enforcement

The three concepts are enforced independently, in this order:

```
InitializeTenancyBySubdomain → EnsureTenantIsReady  (unknown / not provisioned → 404, manually suspended → 403)
  … StartSession → ScopeSessions → EnsureUserIsActive → auth → … → EnsureTenantHasActiveSubscription (route group)
```

- A **manually suspended** tenant is blocked before any session or route, regardless of its subscription. Activating or renewing never lifts a suspension (tested).
- `EnsureTenantHasActiveSubscription` (alias `subscription.active`) protects the **operational routes**: `dashboard`, `employees`, `roles` and all future operations routes placed in that group.
  - Without an active subscription, page requests redirect to `/subscription-required`.
  - JSON and Livewire requests get a **403**.
- **Livewire:** the middleware is registered as Livewire *persistent middleware*, so it is re-applied to every update of a component rendered on a protected page. A user with a page already open cannot keep working after expiry (tested over HTTP with a real component snapshot, and on the real server).
- **Existing sessions** gain nothing: the check runs on every request.
- **Routes reachable without an active subscription** (only when signed in, except the authentication routes):

  | Route | Purpose |
  |---|---|
  | `/login`, `/logout`, `/forgot-password`, `/reset-password/*`, `/user/confirm-password` | Authentication (Fortify) |
  | `/settings/profile`, `/settings/security`, `/settings/appearance` | Own account, including the forced password change |
  | `/subscription-required` | Explains the restriction to every user |
  | `/subscription` | Subscription and billing details (requires `billing.view`). Seat requests need an active term, so they are refused while expired or none. |

### Bug found and fixed: Livewire persistent middleware skipped in long-lived processes
Livewire remembers, per page path, that persistent middleware already ran (`middlewareAppliedFor`) and only resets this in its own test harness. In any long-lived process (Octane workers, the HTTP test client), the **second** Livewire update to the same page in the same process would skip all persistent middleware: `auth`, `can`, and the new subscription check.

Every PHP-FPM request uses a fresh application, so production on PHP-FPM was not affected. Still, `TenancyServiceProvider` now calls `Livewire::flushState()` when each request terminates. The test `an expired subscription restricts the application, including existing sessions and livewire requests` fails without this line (verified by removing it).

---

## 15. Audit Logging

The existing `platform_audit_logs` table is reused, extended with `actor_type`:

| Action | Actor | Recorded values |
|---|---|---|
| `plan.created`, `plan.updated`, `plan.archived`, `plan.activated` | Platform Admin (no tenant) | plan id; old and new name and prices |
| `subscription.activated` | Platform Admin | plan, currency, prices, seats, dates, annual total |
| `subscription.renewed` | Platform Admin | old and new plan, prices, seats and dates; applied reduction in the reason |
| `seats.increase_requested`, `seats.reduction_requested`, `seats.request_cancelled` | **Tenant user** (`actor_type = tenant_user`, `tenant_user_id`, email) | request id, seats, current seats, estimate |
| `seats.request_approved`, `seats.request_rejected` | Platform Admin | old and new seats or next-term seats, amount charged, note |
| `billing.charge_created` | Platform Admin | charge id, type, amount, currency |
| `billing.payment_recorded` | Platform Admin | payment id, amount, date, reference, balance before and after |

Records are immutable, as in Milestone 3. Tenant-user entries are labelled "Tenant user" in the history tables. Plan entries appear as "Platform" on the dashboard. Nothing sensitive is stored: no passwords or payment credentials, and no request payloads.

---

## 16. Security and Tenant Isolation

- **Platform side:**
  - every subscription, billing and plan route needs `auth:platform` plus the `manage-billing` gate, whose `PlatformAdmin` type hint denies any other identity;
  - tenant-bound routes also check `TenantPolicy`;
  - the platform area is bound to the central domain, so these routes return 404 on tenant subdomains (tested).
- **Tenant side:**
  - `/subscription` requires `billing.view`, and requests require `billing.manage`, checked in the action;
  - every query is scoped to `tenant()->getTenantKey()`;
  - a request or cancellation can never target another tenant (tested);
  - prices, seats and amounts come only from central records;
  - the browser sends only the seat count.
- **Validation:**
  - money inputs are validated against the currency precision and limits;
  - seat counts are bounded;
  - dates are validated;
  - lifecycle rules are re-checked in the actions under row locks, never only in the UI.
- **Data integrity:** unique constraints (charge key, pending request, term sequence and start, payment token), restrictive foreign keys, append-only models and locked balance checks.
- **Unchanged:**
  - tenant identification, session scoping and `EnsureUserIsActive`;
  - privilege rules and the per-tenant permission cache bootstrapper.

  The new permissions are created by `SyncTenantAuthorization` like the others.

---

## 17. Existing-Tenant Migration Strategy

- The migration adds `tenants.subscription_required` with **default true** and sets it to **false for every existing tenant**. Existing tenants become **Legacy**: no lockout, no seat limit, no change for their users.
- New tenants, provisioned after the migration, require a subscription. Provisioning and the initial Tenant Admin are unchanged. Until a Platform Admin activates a subscription, users can sign in but see "Subscription required", and **no employees can be added** (0 seats), so unlimited seats are never granted by accident.
- **Transitioning a legacy tenant:** Subscriptions → filter *Legacy* → Manage → Activate. Activation needs at least as many seats as active users. From then on the tenant follows the subscription rules and cannot return to legacy.
- **Permissions for existing tenants:** run `php artisan tenants:sync-authorization` after deploying, so their Tenant Admin role gains `billing.view` and `billing.manage` (tested). New tenants receive them during provisioning.
- **Development database:** `fcl_central` has 0 tenants. When checked during this milestone, the M4 migrations were **already applied** to it (batch 3), and it holds 0 plans and 0 tenants. Running them again is a no-op.

---

## 18. Files and Migrations

### New
| Area | Files |
|---|---|
| Money & pricing | `app/Billing/{Money, Pricing, Proration, SeatGuard, SeatUsage, ActiveUsers, RenewalQuote}.php`, `config/billing.php` |
| Actions | `app/Actions/Billing/{SavePlan, ActivateSubscription, RenewSubscription, RequestSeatChange, ReviewSeatChangeRequest, RecordPayment, CreateCharge}.php` |
| Models | `app/Models/{SubscriptionPlan, TenantSubscription, SubscriptionTerm, SeatChangeRequest, BillingCharge, BillingChargeLine, BillingPayment}.php`, `app/Models/Concerns/IsAppendOnly.php` |
| Enums | `app/Enums/{SubscriptionStatus, SubscriptionTermKind, SeatRequestType, SeatRequestStatus, ChargeType, ChargeStatus}.php` |
| Exceptions | `app/Exceptions/{NoSeatAvailable, InvalidSubscriptionChange}.php` |
| HTTP | `app/Http/Middleware/EnsureTenantHasActiveSubscription.php`, `app/Http/Controllers/Platform/{PlanController, SubscriptionController, SeatRequestController, ChargeController}.php`, `app/Http/Requests/Platform/PlanRequest.php` |
| Views | `resources/views/platform/{plans, subscriptions, seat-requests, charges}/…`, `resources/views/pages/subscription/⚡index.blade.php`, `resources/views/subscription-required.blade.php` |
| Migrations | `2026_10_20_100000_create_subscription_plans_table`, `…100100_create_tenant_subscriptions_tables` (adds `tenants.subscription_required`, backfills legacy), `…100200_create_billing_tables`, `…100300_create_seat_change_requests_table`, `…100400_add_actor_type_to_platform_audit_logs_table` |
| Tests | `tests/Feature/Billing/{PricingTest, PlanManagementTest, SubscriptionLifecycleTest, SeatEnforcementTest, SeatRequestTest, RenewalTest, BillingPaymentTest}.php` |

### Modified
| File | Change |
|---|---|
| `app/Actions/Employees/CreateEmployee.php`, `SetEmployeeActivation.php` | Seat check through `SeatGuard`; reactivating an already-active user is a no-op |
| `resources/views/pages/employees/⚡index.blade.php` | Seat usage line, "no seats" callout, seat errors shown as toasts |
| `app/Models/Tenant.php` | `subscription_required` column, `subscription()`, `subscriptionStatus()`, `seatLimit()` |
| `app/Models/PlatformAuditLog.php`, `app/Enums/PlatformAuditAction.php` | Tenant-user actors, nullable tenant, new actions |
| `app/Enums/TenantPermission.php` | `billing.view`, `billing.manage` |
| `routes/tenant.php`, `bootstrap/app.php` | `subscription.active` group, `/subscription`, `/subscription-required` |
| `app/Providers/TenancyServiceProvider.php` | Persistent Livewire middleware; flushes Livewire state per request |
| `app/Providers/AppServiceProvider.php` | `manage-billing` gate |
| `routes/web.php`, `resources/views/layouts/platform.blade.php`, `resources/views/layouts/app/sidebar.blade.php` | Central billing routes and navigation; tenant "Subscription & billing" link |
| `app/Http/Controllers/Platform/DashboardController.php`, `resources/views/platform/dashboard.blade.php` | Subscription and billing statistics |
| `resources/views/platform/tenants/show.blade.php`, `…/partials/audit-entries.blade.php` | Subscription link; tenant-user and platform-wide audit entries |
| `tests/Pest.php` | `provisionTenant(subscribed: true)` (default), `testPlan()`, `subscribeTenant()` |
| `composer.json`, `composer.lock` | `brick/math ^1.0` declared explicitly |

**Packages:** `brick/math` (1.0.0) was already installed as a Laravel dependency. It is now declared explicitly because the billing code uses it directly, so only the lock file's content hash changed. No other packages were added. Running `composer require` also triggered `boost:update`, which appended generated guidelines to `CLAUDE.md`; that change was reverted, so `CLAUDE.md` is unchanged.

---

## 19. Tests and Results

**114 new tests** in `tests/Feature/Billing`:

| File | Tests | Highlights |
|---|---|---|
| `PricingTest` | 24 | Annual formula (including large values), term ends for leap days and year ends, first and last day proration, leap terms, rounding (including an exact half), invalid seats and dates, money parse and format per currency precision |
| `PlanManagementTest` | 18 | Create in minor units, validation (7 cases), edits never rewrite terms, immutable currency, archive keeps subscriptions valid, platform-only routes, no access from tenant subdomains, form rendering |
| `SubscriptionLifecycleTest` | 16 | Legacy migration, new-tenant policy, activation preview, charge and audit, legacy transition, invalid activations (5), unprovisioned tenant, **expiry blocks pages, existing sessions and Livewire** (Livewire regression), last-day boundary, suspension independence, billing never touches provisioning or suspension fields, platform pages, permission sync |
| `SeatEnforcementTest` | 14 | Admin and employees consume seats, inactive users don't, creation and reactivation blocked, UI message, deactivate-and-replace with no charge, no subscription, expired, legacy unlimited, **lock held by a second MySQL session blocks creation**, lock → count → insert → release order, per-tenant limits, already-active reactivation |
| `SeatRequestTest` | 17 | Tenant request with estimate and tenant-user audit, prorated approval with stored inputs, first-day full price, duplicate approval and charge prevention, rejection, single pending and cancel, invalid requests (5), permissions, cross-tenant protection, reduction scheduling, supersede, term boundaries |
| `RenewalTest` | 11 | Renewal window, on-time continuity, history and snapshots preserved, price-change confirmation, currency mismatch, reduction applied at renewal, never below active users, no duplicate renewal, late renewal, seats added after an early renewal |
| `BillingPaymentTest` | 14 | Partial and full payments with balances and statuses, validation (6), double submission, overpayment, append-only records, unique charge key, read-only tenant history, tenant cannot record payments, company A cannot see company B, outstanding filter and dashboard totals |

All 35 required test areas are covered: 1–6 by PlanManagement and SubscriptionLifecycle; 7–14 by SeatEnforcement; 15–22 by SeatRequest and Pricing; 23–28 by SeatRequest and Renewal; 29–34 by BillingPayment and SubscriptionLifecycle; 35 by the full suite.

**Final results:**
- `composer test`: Pint **passed**, PHPStan **0 errors**, Pest **280 tests: 279 passed, 1 skipped** (the Starter Kit two-factor test; 2FA is disabled), **1,110 assertions**.
- `php artisan test`: the same 280 / 279 / 1 skipped.
- `npm run build`: succeeds. `npm audit`: **0 vulnerabilities**.
- After the runs, only `fcl_central` and `fcl_central_testing` exist; every disposable tenant database was dropped.
- **Earlier tests:** the M1–M3 tests now provision tenants with an active test subscription by default, through the `provisionTenant()` helper. They represent companies in normal operation. No earlier assertion was changed.
- **One unexplained failure:** while the Billing tests were being developed, one run reported an error in `the employees page explains that no seats are available`. It passed in isolation and in **all later runs** (5 more folder runs and 3 full-suite runs). It happened while an editor reformatted a Blade file on disk, which is the likely cause, but this is unconfirmed.

**Real-server check** (PHP built-in server and curl, testing databases only, all data removed afterwards). All 31 checks passed:

| Check | Result |
|---|---|
| Plan created via form; two tenants provisioned via UI | 302, 200, 200 |
| New tenant without a subscription: dashboard / subscription page | 302 to `/subscription-required` / 200 |
| Activation preview shows `1,360.00 USD` (1000 + 3 × 120); activation | shown; 302 |
| Same session after activation: dashboard; employees page shows "1 of 3" | 200; shown |
| **6 parallel processes create employees with 2 seats free** | **2 created, 4 refused (`NoSeatAvailable`)**, 3 active users |
| Seat increase (+2) through a **real Livewire request** | 200; stored pending with the requester's email and estimate 16.44 USD (2 × 120 × 25/365) |
| Approval preview; approve; **approve again** | shown; 302; still exactly 1 charge for the request |
| Employees page after approval | "3 of 5" |
| Payment 500.00; **same form re-submitted**; overpayment | 302; still 1 payment; refused, balance 860.00 USD |
| Tenant billing history | "Partially paid" shown |
| Renewal preview; confirm; **confirm again** | shown; 302; 2 terms and 1 renewal charge |
| Tenant without a subscription | dashboard 302 to `/subscription-required` |
| Manual suspension of the subscribed tenant, then reactivation | dashboard 403, then 200 |
| Simulated expiry (term dates moved into the past in the testing DB) | status `expired`; dashboard 302; **Livewire update 403**; subscription page 200 |
| Audit trail | every operation recorded; the seat request marked as a tenant-user action |
| Server log errors | 0 |

**Browser testing:** not performed. No browser-automation tool was available, so layout and visual behavior are verified only through HTTP rendering. Follow §20.

---

## 20. Manual Browser Verification Steps

Prerequisites:
1. Run `php artisan migrate`. The dev DB already has these migrations.
2. Run `php artisan tenants:sync-authorization` if tenants existed before this milestone.
3. Run `npm run build`, then `php artisan serve`.
4. Have a Platform Admin account.

1. At `/admin`, the dashboard shows the new "Subscriptions & billing" cards, all zero.
2. **Plans → New plan:** "Standard", currency of your choice, base fee and seat price. Check that a value with too many decimals is rejected. Edit the plan and confirm the currency field is locked.
3. **Tenants → New tenant** `acme`. Sign in at `http://acme.localhost:8000` as its Tenant Admin and change the password. You should land on **Subscription required**. Open **Subscription & billing**: status "No subscription".
4. In the platform, go to **Subscriptions** and filter "No subscription". Click **Manage** → **Activate subscription**, pick the plan, 2 seats and today, then **Preview**. Check the formula and the inclusive dates. **Activate.**
5. In the tenant window, the dashboard and Employees now work, and Employees shows "1 of 2 purchased seats in use". Create one employee: it works, and the "No purchased seats are available" callout appears. Try a second employee: an error toast appears.
6. Deactivate the employee, then create another one: it works, and **Billing** in the platform shows no new charge.
7. In **Subscription & billing**, click **Request additional seats**, enter 3, check the estimate, and send. The second request button disappears while one is pending.
8. In the platform, open **Seat requests**: the charge-if-approved-today preview is shown. Approve. The tenant now shows 5 purchased seats, the request is approved, and a new prorated charge appears in billing history.
9. Request **fewer seats at renewal** (for example 3) as the tenant; approve it in the platform. The current seats stay 5, and "Approved: 3 seats from the next renewal" is shown.
10. In the platform, open **Billing** → the initial charge. Record a partial payment and check that the status becomes "Partially paid". Reload the confirmation page and press back/submit again: there should still be one payment. Try an amount above the balance: it is refused.
11. **Renewal:** for a fresh subscription it says "Renewal opens on …". To try a renewal, activate a second tenant with a start date about 340 days ago. Preview the renewal, raise the plan's seat price, preview again (the price-change warning appears), and confirm with the checkbox.
12. **Suspend** the subscribed tenant in **Tenants**: tenant users get 403 even with an active subscription. Reactivate it.
13. Open the tenant's **History** and the dashboard activity. Seat requests show the "Tenant user" badge, and plan actions show "Platform".

---

## 21. Known Limitations and Deferred Features

- **Business policies still open:** real prices, currencies and the currency allow-list; tax and VAT; invoice numbering and legal invoices; payment terms, due dates and dunning; grace periods; late-renewal billing (currently the lapsed period is not billed); refunds and credits. All are technical defaults or out of scope (§3.2).
- **No scheduled jobs:** expiry is date-derived, so no cron is needed. There are no reminders or notifications for renewals, requests or payments.
- **Point-in-time active-user count** at activation or renewal (§4). A tenant can end up over its limit; this is surfaced and blocks further activations, but is not auto-corrected by design.
- **One payment applies to one charge.** There is no allocation of a single bank transfer across several charges, no payment reversal and no manual charge correction. "Administrative correction" is not supported, and records are append-only.
- **Plan changes take effect only at renewal**; there are no mid-term plan upgrades.
- **The tenant subscription page reads central tables** through `CentralConnection` models; no extra authorization layer exists beyond `billing.view`, `billing.manage` and tenant scoping.
- **Livewire upload routes** remain outside the tenant group (unchanged since Milestone 3; uploads are unused).
- **No browser-automation tests.**
- **Not committed:** `app/Console/Commands/CreatePlatformAdmin.php` has a local, uncommitted change that lowers the Platform Admin password minimum from 12 to 6 characters. It was not made by this milestone, was left untouched and is not part of any commit. Review it before committing, because it weakens the platform password policy.

---

## 22. Recommended Next Milestone

**Milestone 5 — FCL Operations foundation** (customers, shipments and containers inside the tenant databases). It would sit behind the existing `subscription.active` route group, the permission catalog and the Default Branch.

A small follow-up for billing operations could add:
- email notifications for renewals due, seat requests and recorded payments;
- a printable charge statement;
- once the business decides them, the open policies listed in §21.
