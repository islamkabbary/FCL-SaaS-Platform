<?php

namespace App\Actions\Billing;

use App\Enums\PlatformAuditAction;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a fixed annual plan.
 *
 * The currency is chosen when the plan is created and never changes, so a plan's
 * prices are always comparable with the terms priced from it. Price changes only
 * affect terms created afterwards; existing terms and charges keep their snapshots.
 */
class SavePlan
{
    /**
     * @param  array{name: string, description: ?string, currency: string, annual_base_fee: int, annual_seat_price: int}  $attributes
     */
    public function create(PlatformAdmin $admin, array $attributes): SubscriptionPlan
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $attributes): SubscriptionPlan {
            $plan = SubscriptionPlan::query()->create([...$attributes, 'currency' => strtoupper($attributes['currency']), 'is_active' => true]);

            PlatformAuditLog::record($admin, null, PlatformAuditAction::PlanCreated, newValues: [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'currency' => $plan->currency,
                'annual_base_fee' => $plan->annual_base_fee,
                'annual_seat_price' => $plan->annual_seat_price,
            ]);

            return $plan;
        });
    }

    /**
     * @param  array{name: string, description: ?string, annual_base_fee: int, annual_seat_price: int}  $attributes
     */
    public function update(PlatformAdmin $admin, SubscriptionPlan $plan, array $attributes): SubscriptionPlan
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $plan, $attributes): SubscriptionPlan {
            $plan = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $plan->fill(array_intersect_key($attributes, array_flip(['name', 'description', 'annual_base_fee', 'annual_seat_price'])));

            if (! $plan->isDirty()) {
                return $plan;
            }

            $changed = array_keys($plan->getDirty());
            $oldValues = ['plan_id' => $plan->id, ...array_intersect_key($plan->getOriginal(), array_flip($changed))];
            $plan->save();

            PlatformAuditLog::record($admin, null, PlatformAuditAction::PlanUpdated, $oldValues, [
                'plan_id' => $plan->id,
                ...array_intersect_key($plan->getAttributes(), array_flip($changed)),
            ]);

            return $plan;
        });
    }

    /**
     * Archive (no longer offered) or reactivate a plan. Subscriptions already priced
     * from an archived plan stay valid; it just cannot be chosen for new terms.
     */
    public function setActive(PlatformAdmin $admin, SubscriptionPlan $plan, bool $active): SubscriptionPlan
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $plan, $active): SubscriptionPlan {
            $plan = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->is_active === $active) {
                return $plan;
            }

            $plan->forceFill(['is_active' => $active])->save();

            PlatformAuditLog::record($admin, null, $active ? PlatformAuditAction::PlanActivated : PlatformAuditAction::PlanArchived, newValues: [
                'plan_id' => $plan->id,
                'name' => $plan->name,
            ]);

            return $plan;
        });
    }
}
