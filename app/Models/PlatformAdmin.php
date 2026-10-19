<?php

namespace App\Models;

use Database\Factories\PlatformAdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform operator account. Lives only in the central database and
 * authenticates through the "platform" guard, never the tenant "web" guard.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $two_factor_secret Encrypted at rest.
 * @property list<string>|null $two_factor_recovery_codes Encrypted at rest.
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_timestep
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class PlatformAdmin extends Authenticatable
{
    /** @use HasFactory<PlatformAdminFactory> */
    use CentralConnection, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
        ];
    }

    /**
     * Whether two-factor authentication is set up and confirmed.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether an authenticator was added but not confirmed with a code yet.
     */
    public function hasPendingTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }
}
