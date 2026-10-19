<?php

namespace App\Models;

use App\Billing\Money;
use App\Enums\SeatRequestStatus;
use App\Enums\SeatRequestType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A Tenant Admin's request to change purchased seats, reviewed by a Platform Admin.
 * Stored centrally; the requesting user's identity is copied from the tenant database.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $tenant_subscription_id
 * @property int $subscription_term_id
 * @property SeatRequestType $type
 * @property int $seats
 * @property SeatRequestStatus $status
 * @property string|null $pending_tenant_id
 * @property int $requested_by_user_id
 * @property string $requested_by_name
 * @property string $requested_by_email
 * @property string|null $request_note
 * @property string $currency
 * @property int|null $estimated_amount
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property int|null $billing_charge_id
 * @property int|null $applied_to_term_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read TenantSubscription $subscription
 * @property-read SubscriptionTerm $term
 * @property-read PlatformAdmin|null $reviewer
 * @property-read BillingCharge|null $charge
 */
class SeatChangeRequest extends Model
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
            'type' => SeatRequestType::class,
            'status' => SeatRequestStatus::class,
            'seats' => 'integer',
            'estimated_amount' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', SeatRequestStatus::Pending);
    }

    /**
     * Approved reductions not yet applied at a renewal.
     *
     * @param  Builder<self>  $query
     */
    public function scopeScheduledReduction(Builder $query): void
    {
        $query->where('type', SeatRequestType::Reduction)->where('status', SeatRequestStatus::Approved);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<TenantSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    /**
     * The term that was current when the request was made.
     *
     * @return BelongsTo<SubscriptionTerm, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTerm::class, 'subscription_term_id');
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<BillingCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(BillingCharge::class, 'billing_charge_id');
    }

    public function format(int $minor): string
    {
        return Money::format($minor, $this->currency);
    }
}
