<?php

namespace App\Models;

use App\Billing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The platform's default price per user and month (one row). New subscriptions and
 * renewals copy it into their term; changing it never affects an existing term.
 *
 * @property int $id
 * @property string $currency
 * @property int $seat_price
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BillingSetting extends Model
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
            'seat_price' => 'integer',
        ];
    }

    /**
     * The configured price, or null while a Platform Admin has not set one yet.
     */
    public static function current(): ?self
    {
        return self::query()->oldest('id')->first();
    }

    public function format(int $minor): string
    {
        return Money::format($minor, $this->currency);
    }
}
