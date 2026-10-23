<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A payment recorded manually by a Platform Admin against one charge. Append-only.
 *
 * @property int $id
 * @property int $billing_charge_id
 * @property string $tenant_id
 * @property string $currency
 * @property int $amount
 * @property Carbon $paid_on
 * @property string|null $reference
 * @property string|null $note
 * @property string $submission_token
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property-read BillingCharge $charge
 * @property-read PlatformAdmin|null $recorder
 */
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class BillingPayment extends Model
{
    use CentralConnection, IsAppendOnly;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['submission_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<BillingCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(BillingCharge::class, 'billing_charge_id');
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'recorded_by');
    }
}
