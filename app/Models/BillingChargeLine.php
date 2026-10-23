<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $billing_charge_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount
 * @property int $amount
 * @property array<string, mixed>|null $calculation
 * @property-read BillingCharge $charge
 */
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class BillingChargeLine extends Model
{
    use CentralConnection, IsAppendOnly;

    public $timestamps = false;

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
            'quantity' => 'integer',
            'unit_amount' => 'integer',
            'amount' => 'integer',
            'calculation' => 'array',
        ];
    }

    /**
     * @return BelongsTo<BillingCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(BillingCharge::class, 'billing_charge_id');
    }
}
