<?php

namespace Database\Seeders;

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\TenantRole;
use App\Models\Branch;
use App\Models\PlatformAdmin;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * The main development accounts: three Platform Admins, and the same three as Tenant Admins
 * of a demo company. Development only, because their shared password is weak and comes from
 * SEED_ACCOUNTS_PASSWORD in .env. It can run again: existing accounts are updated.
 */
class MainAccountsSeeder extends Seeder
{
    /**
     * @var array<string, string> Username => name.
     */
    protected const Accounts = [
        'islam' => 'Islam',
        'zyad' => 'Zyad',
        'ibrahim' => 'Ibrahim',
    ];

    protected const DemoSubdomain = 'demo';

    protected const DemoSeats = 50;

    public function __construct(
        protected ProvisionTenant $provisionTenant,
        protected ActivateSubscription $activateSubscription,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('MainAccountsSeeder must not run in production.');
        }

        $password = config('platform.seed_accounts_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set SEED_ACCOUNTS_PASSWORD in .env before seeding the main accounts.');
        }

        $admins = collect(self::Accounts)->map(fn (string $name, string $username): PlatformAdmin => $this->platformAdmin($username, $name, $password));

        $tenant = $this->demoTenant($admins->first());

        $tenant->run(function () use ($password): void {
            foreach (self::Accounts as $username => $name) {
                $this->tenantAdmin($username, $name, $password);
            }
        });
    }

    protected function platformAdmin(string $username, string $name, string $password): PlatformAdmin
    {
        $admin = PlatformAdmin::query()->whereLogin($username)->orWhere('email', $this->email($username))->first()
            ?? new PlatformAdmin(['email' => $this->email($username)]);

        $admin->forceFill(['name' => $name, 'username' => $username, 'password' => $password])->save();

        return $admin;
    }

    protected function demoTenant(PlatformAdmin $admin): Tenant
    {
        $domain = Domain::query()->where('domain', self::DemoSubdomain)->first();

        if ($domain !== null) {
            /** @var Tenant */
            return $domain->tenant;
        }

        $firstUsername = array_key_first(self::Accounts);

        $tenant = $this->provisionTenant->create([
            'company_name' => 'Demo Freight',
            'subdomain' => self::DemoSubdomain,
            'admin_name' => self::Accounts[$firstUsername],
            'admin_email' => $this->email($firstUsername),
        ])->tenant;

        $plan = SubscriptionPlan::query()->firstOrCreate(['name' => 'Demo'], [
            'currency' => 'USD',
            'annual_base_fee' => 100000,
            'annual_seat_price' => 12000,
            'is_active' => true,
        ]);

        $this->activateSubscription->handle($admin, $tenant, $plan, self::DemoSeats, CarbonImmutable::today());

        return $tenant;
    }

    protected function tenantAdmin(string $username, string $name, string $password): void
    {
        $user = User::query()->whereLogin($username)->orWhere('email', $this->email($username))->first()
            ?? new User(['email' => $this->email($username)]);

        $user->forceFill([
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'primary_branch_id' => $user->primary_branch_id ?? Branch::query()->where('is_default', true)->value('id'),
            'is_tenant_admin' => true,
            'must_change_password' => false,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole(TenantRole::TenantAdmin->value);
    }

    protected function email(string $username): string
    {
        return $username.'@fcl.test';
    }
}
