<?php

namespace App\Models;

use App\Billing\Money;
use App\Enums\ChargeStatus;
use App\Enums\ChargeType;
use App\Models\Builders\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An internal commercial charge (not a tax invoice). Its lines never change and it is
 * never deleted; only its percentage discount (and so the amount due) can change, and
 * only while nothing has been paid (ApplyChargeDiscount). Payments are recorded
 * against it separately.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $tenant_subscription_id
 * @property int|null $subscription_term_id
 * @property ChargeType $type
 * @property string $currency
 * @property int $subtotal
 * @property int $discount_basis_points
 * @property int $discount_amount
 * @property int $amount
 * @property string $description
 * @property int|null $period_index
 * @property Carbon|null $period_starts_on
 * @property Carbon|null $period_ends_on
 * @property string $idempotency_key
 * @property Carbon $issued_on
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $payments_sum_amount
 * @property-read Tenant $tenant
 * @property-read SubscriptionTerm|null $term
 * @property-read Collection<int, BillingChargeLine> $lines
 * @property-read Collection<int, BillingPayment> $payments
 */
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class BillingCharge extends Model
{
    use CentralConnection;

    /**
     * The only attributes that may change after the charge is created.
     *
     * @var list<string>
     */
    public const Discountable = ['discount_basis_points', 'discount_amount', 'amount', 'updated_at'];

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
            'subtotal' => 'integer',
            'discount_basis_points' => 'integer',
            'discount_amount' => 'integer',
            'amount' => 'integer',
            'period_index' => 'integer',
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'issued_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $charge): void {
            if (array_diff(array_keys($charge->getDirty()), self::Discountable) !== []) {
                throw new LogicException('Only the discount of a charge can be changed.');
            }

            if ($charge->amount !== $charge->subtotal - $charge->discount_amount) {
                throw new LogicException('The amount of a charge must be its subtotal less its discount.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('BillingCharge records cannot be deleted.'));
    }

    /**
     * Saving a loaded charge runs the "updating" guard above (discount fields only, amount
     * consistent with them). Mass updates through the query builder stay refused by the
     * AppendOnlyBuilder, which would otherwise also block this save.
     *
     * @param  Builder<self>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        return parent::performUpdate((new Builder($this->newBaseQueryBuilder()))->setModel($this));
    }

    /**
     * Unpaid charges issued more than "overdue_after_days" days ago.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->outstanding()->whereDate('issued_on', '<', now()->subDays((int) config('billing.overdue_after_days'))->toDateString());
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

    public function hasDiscount(): bool
    {
        return $this->discount_basis_points > 0;
    }

    /**
     * The discount as a percentage string, e.g. 1250 → "12.5".
     */
    public function discountPercent(): string
    {
        return rtrim(rtrim(number_format($this->discount_basis_points / 100, 2, '.', ''), '0'), '.');
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
