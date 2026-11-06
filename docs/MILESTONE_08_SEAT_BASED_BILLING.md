# Milestone 08: Seat-Based Billing (Setup Fee + Monthly Charge)

Date: 2026-11-06

This milestone removes subscription plans. A company is now charged by its number of users, at one price per user per month that the platform sets.

The owner confirmed these rules in a planning session on 2026-11-06. They replace the plan rules of Milestone 4 (`docs/MILESTONE_04_SUBSCRIPTIONS_BILLING.md` §3.1 and §6) and the plan decisions in `docs/FCL_SaaS_Complete_Project_Handover_2026-10-19.md` §2.4.

## 1. Business rules (owner decisions)

| Topic | Rule |
|---|---|
| Price | One default price per user per month, for example 18.00 USD. Plans no longer exist. |
| Setup fee | Paid **once per user**. When a subscription starts it is price × 12 × users. Example: 18 × 12 × 100 = 21,600.00 USD. |
| Monthly charge | Price × users, issued automatically at the start of every month of the term. Example: 18 × 100 = 1,800.00 USD. |
| Users added during the year | Setup fee = price × remaining months × added users, then the monthly charge covers the new total. Example: 20 users added in the seventh month pay 18 × 6 × 20 = 2,160.00 USD, then 120 × 18 = 2,160.00 USD a month until the yearly term ends. |
| Remaining months | Counted in whole months. The month that has already started counts in full. |
| Renewal | No new setup fee. Only users above the most that ever paid a setup fee pay one, for 12 months. Example: two years later the company goes from 120 to 150 users, so it pays a setup fee for the 30 new users only. |
| Users bought back | Example: a company goes from 120 to 100 users at renewal, then back to 120. The 20 users it adds back pay no second setup fee. |
| Reducing users | Only at renewal, with no refund. This is unchanged from Milestone 4. |
| Price change | Applies to new subscriptions and renewals only. A term keeps its price for its whole year, including for users added during it. |
| Discounts | A percentage, from 0 to 100% with two decimals. It can be set on the setup fee when a subscription is activated or renewed, and on any charge (setup or monthly) until a payment is recorded. |
| Non-payment | Unpaid charges are listed as overdue after `billing.overdue_after_days` (7 by default). Suspending the company stays a manual decision through the existing suspension. |

## 2. Architecture

The annual term model of Milestone 4 stays: [`SubscriptionTerm`](../app/Models/SubscriptionTerm.php) records, access derived from their dates, `SeatGuard` and `EnsureTenantHasActiveSubscription`. Only the pricing and the billing changed.

- **Price setting.** [`BillingSetting`](../app/Models/BillingSetting.php) is a central single-row table holding `currency` and `seat_price`. A Platform Admin edits it at `admin/billing-settings`, which [`SaveBillingSettings`](../app/Actions/Billing/SaveBillingSettings.php) audits as `billing.settings_updated`.
- **Term snapshot.** Each term copies `seat_price` and `currency` and never changes them.
- **Once per seat.** `tenant_subscriptions.setup_paid_seats` holds the most seats ever charged a setup fee. `TenantSubscription::seatsNeedingSetup()` returns the seats above it.
- **Monthly periods.** A term has 12 periods. `Pricing::periodBounds()` counts every period from the term start, never chained, so a term starting on 31 January has periods starting on 28 February, then 31 March. The last period ends on the renewal date.
- **Calculations.** [`Pricing`](../app/Billing/Pricing.php) handles them: `setupFee`, `monthlyFee`, `periodIndex`, `remainingMonths`, `prorate` (the setup fee of added seats), `discount` and `percentToBasisPoints`. Amounts are integers in minor units, and a discount is rounded once, half up.
- **Charges.** `billing_charges` now has `subtotal`, `discount_basis_points`, `discount_amount`, `amount` (what is due) and the billed period (`period_index`, `period_starts_on`, `period_ends_on`). The charge types are `setup`, `monthly` and `seat_setup`.
- **Editable discount only.** The model guard lets only the discount fields change, and only if `amount = subtotal − discount_amount`. Deleting a charge is refused. `performUpdate()` lets a loaded charge be saved, while mass updates stay refused by `AppendOnlyBuilder`. Lines and payments stay append-only.

### Actions

| Action | What changed |
|---|---|
| `ActivateSubscription` | Uses the current price. Creates the setup fee (12 months, optional discount) and the first monthly charge, and sets `setup_paid_seats`. Refused if no price is set, or if the setup fee changed after the preview. |
| `RenewSubscription` | Snapshots the current price, which must be confirmed if it changed. A setup fee is charged only for the seats above `setup_paid_seats`. Issues the first month when a late renewal starts today. |
| `ReviewSeatChangeRequest` + `SeatIncreaseQuote` | Approving an increase charges a `seat_setup` fee for the remaining months, for the new seats above `setup_paid_seats`. There is no charge when there are none. With an early renewal, the seats are added to the next term as well, with no second fee. |
| `IssueMonthlyCharges` (new) | Issues every started period that has no charge yet, for the term's seats at that time. It catches up missed periods, and its idempotency key is `monthly:{term}:{period}`. It takes the same subscription lock as a seat approval. |
| `ApplyChargeDiscount` (new) | Locks the charge (the same lock as `RecordPayment`), refuses once a payment exists, recomputes the amount and audits it as `billing.charge_discount_applied`. |
| `CreateCharge` | Takes the discount and the period. A null admin means the system issued the charge (audited with `recordSystem`). |

### Scheduler

`billing:issue-monthly-charges` is scheduled daily at 01:00 in `routes/console.php`, with `withoutOverlapping()->onOneServer()`. A period is billed on its first day. Users approved during a month are therefore billed monthly from the next month: their setup fee already covers the month in progress.

## 3. UI

**Platform**
- The **Plans** nav item is replaced by **Price per user** (`platform.billing-settings.edit`).
- Activating a subscription asks for the users, the start date and an optional discount on the setup fee. The preview shows the setup fee, the discount and the monthly charge.
- The renewal preview shows the monthly charge, the setup fee for new users only, and a warning when the price changed.
- The subscription page shows the price per user, the monthly charge and the users whose setup fee is paid.
- The charge page shows the period, the subtotal and discount lines, and a discount form while nothing is paid.
- The charges list has an **Overdue** filter, and the dashboard has an **Overdue charges** card.
- Seat requests show the setup fee if approved today, or that there is none.

**Company (tenant)**
- The subscription page shows the price per user, the monthly charge and the next monthly charge date.
- The estimate for additional users shows the setup fee and the extra monthly amount.
- The billing history shows discounts.

All new text has Arabic translations in `lang/ar.json`. The keys used only by the removed plan screens were removed. Amounts are shown inside `<bdi dir="ltr">`.

## 4. Files

**Added:**
- `app/Models/BillingSetting.php`
- `app/Actions/Billing/{SaveBillingSettings,IssueMonthlyCharges,ApplyChargeDiscount}.php`
- `app/Console/Commands/IssueMonthlyCharges.php`
- `app/Http/Controllers/Platform/BillingSettingsController.php`
- `app/Http/Requests/Platform/BillingSettingsRequest.php`
- `resources/views/platform/billing-settings/edit.blade.php`
- `tests/Feature/Billing/{BillingSettingsTest,ChargeDiscountTest,MonthlyChargesTest}.php`

**Removed:**
- `app/Models/SubscriptionPlan.php`
- `app/Actions/Billing/SavePlan.php`
- `app/Http/Controllers/Platform/PlanController.php`
- `app/Http/Requests/Platform/PlanRequest.php`
- `resources/views/platform/plans/*`
- the `platform.plans.*` routes
- `tests/Feature/Billing/PlanManagementTest.php` (approved with the plan)

**Changed:**
- `app/Billing/*`, `app/Actions/Billing/*` and the billing models
- `ChargeType` and `PlatformAuditAction`
- the subscription, charge and dashboard controllers
- `routes/web.php`, `routes/console.php` and `config/billing.php`
- the platform billing views and the tenant subscription page
- `MainAccountsSeeder`, which now sets 18.00 USD per user
- `tests/Pest.php`: `testPlan()` is replaced by `testPrice()`, and `subscribeTenant()` uses the current price
- the existing billing tests

## 5. Migrations

There is no production data, so, as the owner decided, the existing create migrations were edited instead of adding new ones:

- `2026_10_20_100000_create_subscription_plans_table` was renamed to `create_billing_settings_table`.
- `create_tenant_subscriptions_tables`:
  - `tenant_subscriptions` drops `subscription_plan_id` and adds `setup_paid_seats`.
  - `subscription_terms` replaces the plan columns with `seat_price`.
- `create_billing_tables`: `billing_charges` gains the discount and period columns, timestamps and an `(issued_on, id)` index.

The central database must be rebuilt: `php artisan migrate:fresh --seed` (local only). No tenant migration changed.

## 6. Tests

These test runs passed:

- `tests/Feature/Billing`, now 13 files.
- `tests/Feature/Hardening`, `tests/Feature/Platform`, `tests/Feature/Tenancy/TenantProvisioningTest.php`, `tests/Feature/Impersonation/{RestrictionPolicyTest,SupportAccessTest}.php`, `tests/Feature/SecurityHardeningTest.php` and `tests/Feature/TranslationCompletenessTest.php`. Of 435 tests, 434 passed; the one failure (3 missing Arabic translations) was fixed and that file rerun.

The whole suite was not run.

The owner's example is covered in `PricingTest` and `MonthlyChargesTest`: 100 users, then 20 added in the seventh month, then the monthly charges for the rest of the year.

## 7. Deployment

- Run cron for the scheduler: `* * * * * php artisan schedule:run`. Without it, no monthly charge is issued; a later run catches up.
- Rebuild the central database (§5).
- `npm run build`.
- Set the price per user under **Price per user** before activating any subscription. Activation is refused until it is set.

## 8. Known limitations and open points

- The seats on a monthly charge are those on the day it is issued. A period caught up late uses the seats of that later day.
- These are internal commercial records, not tax invoices. VAT, invoice numbering and dunning stay open, as in Milestone 4 §21.
- Suspension for non-payment is manual. There is no grace-period automation.
- The monthly charge is not pro-rated when a renewal confirmed after expiry starts mid-month: the term starts that day and its periods count from it.

## 9. Manual verification

1. Set the price to 18.00 USD under **Price per user**.
2. Activate a tenant with 100 users and a 10% discount. Check the setup fee of 21,600.00 less 2,160.00, and a first monthly charge of 1,800.00.
3. Run `php artisan billing:issue-monthly-charges` on a later month, for example after shifting the server date. One new monthly charge appears per started month.
4. Request 20 users from the tenant page and check the estimate. Approve it and check the setup fee for the remaining months. The next monthly charge covers 120 users.
5. Discount a monthly charge, record a payment, and check that the discount form disappears.
6. Renew with the same users: there is no setup fee. Renew with more: only the extra users pay one.
7. Check the pages in Arabic and in English.
