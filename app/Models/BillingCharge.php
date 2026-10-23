<?php

namespace App\Models;

use App\Billing\Money;
use App\Enums\ChargeStatus;
use App\Enums\ChargeType;
use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An internal commercial charge (not a tax invoice). Append-only: the amount and its
 * lines never change; payments are recorded against it separately.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $tenant_subscription_id
 * @property int|null $subscription_term_id
 * @property ChargeType $type
 * @property string $currency
 * @property int $amount
 * @property string $description
 * @property string $idempotency_key
 * @property Carbon $issued_on
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property int|null $payments_sum_amount
 * @property-read Tenant $tenant
 * @property-read SubscriptionTerm|null $term
 * @property-read Collection<int, BillingChargeLine> $lines
 * @property-read Collection<int, BillingPayment> $payments
 */
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class BillingCharge extends Model
{
    use CentralConnection, IsAppendOnly;

    public const UPDATED_AT = null;

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
            'type' => ChargeType::class,
            'amount' => 'integer',
            'issued_on' => 'date',
        ];
    }

    /**
     * Add the paid total as "payments_sum_amount".
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithPaidTotal(Builder $query): void
    {
        $query->withSum('payments', 'amount');
    }

    /**
     * Charges with an unpaid balance.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereRaw('amount > (select coalesce(sum(p.amount), 0) from billing_payments as p where p.billing_charge_id = billing_charges.id)');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<SubscriptionTerm, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTerm::class, 'subscription_term_id');
    }

    /**
     * @return HasMany<BillingChargeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillingChargeLine::class);
    }

    /**
     * @return HasMany<BillingPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * Total paid, using the preloaded sum when available.
     */
    public function paidAmount(): int
    {
        return (int) ($this->payments_sum_amount ?? $this->payments()->sum('amount'));
    }

    public function balance(): int
    {
        return $this->amount - $this->paidAmount();
    }

    public function status(): ChargeStatus
    {
        return ChargeStatus::for($this->amount, $this->paidAmount());
    }

    public function format(int $minor): string
    {
        return Money::format($minor, $this->currency);
    }
}
