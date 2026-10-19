<?php

namespace App\Billing;

use App\Exceptions\NoSeatAvailable;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Enforces the purchased seat limit of the current tenant (tenant context only).
 *
 * The entitlement lives in the central database and the users in the tenant database,
 * so no single transaction can cover both. Every seat-consuming operation of a tenant
 * therefore runs while holding a MySQL named lock that is specific to that tenant
 * (GET_LOCK on the central connection; named locks are server-wide and independent of
 * transactions). Under the lock the entitlement is re-read, active users are counted
 * and the tenant-database write is committed, so two concurrent requests cannot both
 * take the last seat.
 */
class SeatGuard
{
    /**
     * Run a callback that activates one more user, if a purchased seat is available.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws NoSeatAvailable
     */
    public function claim(Closure $callback): mixed
    {
        $tenant = $this->tenant();

        // Legacy tenants (created before subscriptions) have no seat limit until transitioned.
        if ($tenant->seatLimit() === null) {
            return $callback();
        }

        $central = DB::connection(config('tenancy.database.central_connection'));
        $lock = 'fcl_seats_'.$tenant->getTenantKey();

        $acquired = $central->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lock, (int) config('billing.seat_lock_timeout')]);

        if ((int) ($acquired->acquired ?? 0) !== 1) {
            throw NoSeatAvailable::busy();
        }

        try {
            $usage = $this->usage();

            if ($usage->available() < 1) {
                throw NoSeatAvailable::forUsage($usage);
            }

            return $callback();
        } finally {
            $central->select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * The current tenant's seat limit and active users.
     */
    public function usage(): SeatUsage
    {
        return new SeatUsage(
            $this->tenant()->seatLimit(),
            User::query()->active()->count(),
        );
    }

    protected function tenant(): Tenant
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('Seats can only be checked in tenant context.');
        }

        return $tenant;
    }
}
