<?php

namespace App\Actions\Billing;

use App\Billing\Pricing;
use App\Enums\ChargeType;
use App\Models\BillingCharge;
use App\Models\SubscriptionTerm;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issues the monthly charge of every period that has started and has none yet.
 *
 * - A period is billed in advance, on its first day, for the term's seats at that time:
 *   seats approved during a month are therefore billed from the next monthly charge.
 * - A period missed while the scheduler was not running is issued on the next run.
 * - The idempotency key "monthly:{term}:{period}" makes it impossible to bill a period twice.
 */
class IssueMonthlyCharges
{
    public function __construct(protected CreateCharge $createCharge) {}

    /**
     * Issue every due monthly charge of every subscription. Returns the number issued.
     */
    public function handle(?CarbonImmutable $today = null): int
    {
        $today = Pricing::day($today ?? CarbonImmutable::today());
        $issued = 0;

        SubscriptionTerm::query()
            ->whereDate('starts_on', '<=', $today->toDateString())
            ->whereHas('subscription')
            ->has('monthlyCharges', '<', Pricing::MonthsPerTerm)
            ->chunkById(100, function ($terms) use ($today, &$issued): void {
                foreach ($terms as $term) {
                    $issued += DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($term, $today): int {
                        // Same lock as a seat approval, so a period is billed with a consistent seat count.
                        TenantSubscription::query()->whereKey($term->tenant_subscription_id)->lockForUpdate()->firstOrFail();

                        return $this->forTerm(SubscriptionTerm::query()->findOrFail($term->id), $today);
                    });
                }
            });

        return $issued;
    }

    /**
     * Issue the due monthly charges of one term. Must run inside the central transaction
     * that holds the subscription lock.
     */
    public function forTerm(SubscriptionTerm $term, ?CarbonImmutable $today = null): int
    {
        $today = Pricing::day($today ?? CarbonImmutable::today());
        $started = $term->startedPeriods($today);

        if ($started === 0) {
            return 0;
        }

        $billed = BillingCharge::query()
            ->where('subscription_term_id', $term->id)
            ->where('type', ChargeType::Monthly)
            ->pluck('period_index')
            ->all();

        $issued = 0;

        foreach (range(0, $started - 1) as $index) {
            if (in_array($index, $billed, true)) {
                continue;
            }

            [$startsOn, $endsOn] = Pricing::periodBounds($term->starts_on, $index);

            $this->createCharge->handle(
                null,
                $term->subscription->tenant,
                $term->subscription,
                $term,
                ChargeType::Monthly,
                "monthly:{$term->id}:{$index}",
                __('Monthly subscription :from – :to', ['from' => $startsOn->toDateString(), 'to' => $endsOn->subDay()->toDateString()]),
                CreateCharge::monthlyLines($term, $startsOn, $endsOn),
                period: ['index' => $index, 'starts_on' => $startsOn, 'ends_on' => $endsOn],
            );

            $issued++;
        }

        return $issued;
    }
}
