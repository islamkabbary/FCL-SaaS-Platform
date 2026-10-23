<?php

namespace App\Session;

use App\Impersonation\Impersonator;
use Illuminate\Database\Query\Builder;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * Database session handler that resolves its connection when the session is
 * read or written instead of when the handler is built.
 *
 * The session store can be created before tenancy is initialized (e.g. when a
 * controller such as Fortify's injects the session guard while the router gathers
 * middleware). The stock handler would then stay bound to the central connection
 * for the whole request. Resolving the default connection lazily stores tenant
 * sessions in the tenant database and central sessions in the central database.
 */
class TenantAwareDatabaseSessionHandler extends DatabaseSessionHandler
{
    /**
     * Get a fresh query builder instance for the table on the current default connection.
     */
    protected function getQuery(): Builder
    {
        return $this->container->make('db')->connection()->table($this->table)->useWritePdo();
    }

    /**
     * The owner stored with the session row. During an impersonation the browser is
     * signed in as the target, but it belongs to whoever started the impersonation: the
     * Tenant Admin for an employee view, nobody in the company for support access.
     *
     * Signing a user out everywhere deletes their rows by user_id. Storing the target
     * there would also delete the impersonating browser (logging the Tenant Admin out
     * instead of returning them), while ending the Tenant Admin's own sessions would
     * leave their impersonating browser alive.
     *
     * @return int|string|null
     */
    protected function userId()
    {
        $marker = $this->container->bound('session.store')
            ? $this->container->make('session.store')->get(Impersonator::SessionKey)
            : null;

        if (is_array($marker)) {
            $impersonator = $marker['impersonator'] ?? null;

            return is_int($impersonator) ? $impersonator : null;
        }

        return parent::userId();
    }
}
