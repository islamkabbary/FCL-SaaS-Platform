<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Features;
use RuntimeException;
use Stancl\Tenancy\Events\DatabaseCreated;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only central database the suite may migrate and wrap in transactions.
     */
    protected const CentralTestingDatabase = 'fcl_central_testing';

    /**
     * The only prefix of tenant databases the suite may create and drop.
     */
    protected const TenantTestingDatabasePrefix = 'fcl_test_tenant_';

    /**
     * Only the central connection is wrapped in a transaction. Tenant databases are
     * created per test and dropped afterwards, because CREATE DATABASE cannot be
     * rolled back.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = ['mysql'];

    /**
     * Tenant databases created by the current test, and therefore safe to drop.
     *
     * @var list<string>
     */
    protected array $tenantDatabasesCreatedByThisTest = [];

    /**
     * Creates the application, refusing to continue (and therefore to migrate
     * anything) unless the suite targets the dedicated testing databases.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->ensureDisposableDatabasesAreConfigured($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(DatabaseCreated::class, function (DatabaseCreated $event): void {
            $this->tenantDatabasesCreatedByThisTest[] = $event->tenant->database()->getName();
        });

        $this->beforeApplicationDestroyed(fn () => $this->dropTenantDatabasesCreatedByThisTest());
    }

    protected function ensureDisposableDatabasesAreConfigured(Application $app): void
    {
        $config = $app->make('config');
        $centralDatabase = $config->get('database.connections.mysql.database');
        $tenantPrefix = $config->get('tenancy.database.prefix');

        if (! $app->environment('testing')
            || $config->get('database.default') !== 'mysql'
            || $centralDatabase !== self::CentralTestingDatabase
            || $tenantPrefix !== self::TenantTestingDatabasePrefix) {
            throw new RuntimeException(sprintf(
                'Refusing to run: tests must use the [%s] central database and the [%s] tenant prefix in the testing environment (got [%s] and [%s]).',
                self::CentralTestingDatabase, self::TenantTestingDatabasePrefix, $centralDatabase, $tenantPrefix,
            ));
        }
    }

    /**
     * Drop the tenant databases this test created, after verifying each one is disposable.
     */
    protected function dropTenantDatabasesCreatedByThisTest(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        DB::purge('tenant');

        $connection = DB::connection('tenant_template');

        foreach (array_unique($this->tenantDatabasesCreatedByThisTest) as $database) {
            $isDisposable = app()->environment('testing')
                && preg_match('/^'.self::TenantTestingDatabasePrefix.'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $database) === 1;

            if (! $isDisposable) {
                throw new RuntimeException("Refusing to drop database [{$database}]: it is not a disposable test tenant database.");
            }

            $exists = $connection->selectOne('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]);

            if ($exists !== null) {
                $connection->statement("DROP DATABASE `{$database}`");
            }
        }

        $this->tenantDatabasesCreatedByThisTest = [];

        DB::purge('tenant_template');
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
