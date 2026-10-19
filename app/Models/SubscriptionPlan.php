<?php

namespace App\Models;

use App\Billing\Money;
use App\Billing\Pricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A fixed, annual-only subscription plan. Prices are integers in minor units of the
 * plan's currency. Changing a plan never changes existing terms or charges: those
 * keep their own pricing snapshots.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $currency
 * @property int $annual_base_fee
 * @property int $annual_seat_price
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubscriptionPlan extends Model
{
    use CentralConnection;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'description', 'currency', 'annual_base_fee', 'annual_seat_price', 'is_active'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annual_base_fee' => 'integer',
            'annual_seat_price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The annual total for the given number of seats at the plan's current prices.
     */
    public function annualTotal(int $seats): int
    {
        return Pricing::annualTotal($this->annual_base_fee, $this->annual_seat_price, $seats);
    }

    public function format(int $minor): string
    {
        return Money::format($minor, $this->currency);
    }
}
