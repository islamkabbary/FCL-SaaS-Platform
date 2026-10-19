<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\ProvisionTenant;
use App\Exceptions\TenantProvisioningFailed;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenants:provision {tenant : The tenant ID or subdomain}')]
#[Description('Resume provisioning of a tenant that is not ready (e.g. after a failure)')]
class ProvisionTenantCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProvisionTenant $provisionTenant): int
    {
        $identifier = $this->argument('tenant');

        if (! is_string($identifier)) {
            return self::INVALID;
        }

        $tenant = Tenant::query()
            ->whereKey($identifier)
            ->orWhereHas('domains', fn ($query) => $query->where('domain', $identifier))
            ->first();

        if ($tenant === null) {
            $this->components->error("Tenant [{$identifier}] not found.");

            return self::FAILURE;
        }

        if ($tenant->isReady()) {
            $this->components->warn("Tenant [{$tenant->name}] is already provisioned.");

            return self::SUCCESS;
        }

        try {
            $result = $provisionTenant->provision($tenant);
        } catch (TenantProvisioningFailed) {
            $this->components->error("Provisioning failed again. See the tenant's provisioning_error and the application log.");

            return self::FAILURE;
        }

        $this->components->info("Tenant [{$tenant->name}] is ready.");
        $this->components->twoColumnDetail('Login URL', $tenant->url('login'));
        $this->components->twoColumnDetail('Tenant Admin', $result->adminEmail);
        $this->components->twoColumnDetail('Temporary password (shown once)', $result->temporaryPassword);

        return self::SUCCESS;
    }
}
