<?php

namespace App\Models;

use App\Billing\Money;
use App\Billing\Pricing;
use App\Enums\SubscriptionTermKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One annual term of a subscription, covering the half-open range [starts_on, ends_on):
 * ends_on is the renewal date, the first day the term no longer covers.
 *
 * The plan name, currency and prices are a snapshot taken when the term was created
 * and can never change. Only "seats" (the purchased entitlement) grows when a seat
 * increase is approved; "initial_seats" keeps the quantity the term started with.
 *
 * @property int $id
 * @property int $tenant_subscription_id
 * @property string $tenant_id
 * @property int $sequence
 * @property SubscriptionTermKind $kind
 * @property int $subscription_plan_id
 * @property string $plan_name
 * @property string $currency
 * @property int $annual_base_fee
 * @property int $annual_seat_price
 * @property int $initial_seats
 * @property int $seats
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TenantSubscription $subscription
 */
class SubscriptionTerm extends Model
{
    use CentralConnection;

    /**
     * Attributes that are part of the immutable snapshot.
     *
     * @var list<string>
     */
    public const Snapshot = [
        'tenant_subscription_id', 'tenant_id', 'sequence', 'kind', 'subscription_plan_id', 'plan_name',
        'currency', 'annual_base_fee', 'annual_seat_price', 'initial_seats', 'starts_on', 'ends_on', 'created_by',
    ];

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
            'kind' => SubscriptionTermKind::class,
            'sequence' => 'integer',
            'annual_base_fee' => 'integer',
            'annual_seat_price' => 'integer',
            'initial_seats' => 'integer',
            'seats' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $term): void {
            if ($term->isDirty(self::Snapshot)) {
                throw new LogicException('The pricing snapshot and dates of a subscription term cannot be changed.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Subscription terms cannot be deleted.'));
    }

    /**
     * Terms covering the given day: starts_on <= day < ends_on.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCovering(Builder $query, CarbonImmutable $day): void
    {
        $date = $day->toDateString();

        $query->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>', $date);
    }

    /**
     * @return BelongsTo<TenantSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    /**
     * The term's annual total for its initial seats at its snapshot prices.
     */
    public function annualTotal(?int $seats = null): int
    {
        return Pricing::annualTotal($this->annual_base_fee, $this->annual_seat_price, $seats ?? $this->initial_seats);
    }

    /**
     * The last day the term covers (the day before the renewal date).
     */
    public function lastDay(): CarbonImmutable
    {
        return $this->ends_on->subDay();
    }

    public function totalDays(): int
    {
        return Pricing::daysBetween($this->starts_on, $this->ends_on);
    }

    public function covers(CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        return $this->starts_on->toDateString() <= $date && $date < $this->ends_on->toDateString();
    }

    public function format(int $minor): string
    {
        return Money::format($minor, $this->currency);
    }
}
