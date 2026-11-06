<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A tenant's subscription (at most one per tenant). The commercial terms live in its
 * annual SubscriptionTerm records; the currency is fixed for the subscription's life.
 *
 * "setup_paid_seats" is the most seats a setup fee was ever charged for. A seat pays its
 * setup fee once: seats bought again after a reduction are not charged a second time.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $currency
 * @property int $setup_paid_seats
 * @property Carbon $activated_at
 * @property int|null $activated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
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
            'setup_paid_seats' => 'integer',
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
     * Seats above those already charged a setup fee, if the subscription grew to the given count.
     */
    public function seatsNeedingSetup(int $seats): int
    {
        return max(0, $seats - $this->setup_paid_seats);
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
