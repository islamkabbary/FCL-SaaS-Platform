<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A tenant's annual subscription (at most one per tenant). The commercial terms live
 * in its SubscriptionTerm records; the currency is fixed for the subscription's life.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $subscription_plan_id
 * @property string $currency
 * @property Carbon $activated_at
 * @property int|null $activated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read SubscriptionPlan $plan
 */
class TenantSubscription extends Model
{
    use CentralConnection;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * The plan used for the latest term (and proposed for the next renewal).
     *
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * @return HasMany<SubscriptionTerm, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(SubscriptionTerm::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<BillingCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(BillingCharge::class);
    }

    /**
     * The term covering the given day, read fresh from the database.
     */
    public function termOn(?CarbonImmutable $day = null): ?SubscriptionTerm
    {
        return SubscriptionTerm::query()
            ->where('tenant_subscription_id', $this->id)
            ->covering($day ?? CarbonImmutable::today())
            ->first();
    }

    /**
     * The most recent term (the one with the latest end date).
     */
    public function latestTerm(): ?SubscriptionTerm
    {
        return SubscriptionTerm::query()
            ->where('tenant_subscription_id', $this->id)
            ->orderByDesc('sequence')
            ->first();
    }
}
