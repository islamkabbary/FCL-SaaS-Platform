<?php

namespace App\Session;

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
}
