<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;

/**
 * Outcome of a successful tenant provisioning run.
 *
 * Carries the Tenant Admin's temporary password so it can be shown exactly
 * once. It must never be persisted, flashed to the session, or logged.
 */
final readonly class ProvisioningResult
{
    public function __construct(
        public Tenant $tenant,
        public string $adminEmail,
        #[\SensitiveParameter] public string $temporaryPassword,
    ) {}

    /**
     * Hide the temporary password from dumps and debug output.
     *
     * @return array{tenant: string, adminEmail: string, temporaryPassword: string}
     */
    public function __debugInfo(): array
    {
        return [
            'tenant' => $this->tenant->id,
            'adminEmail' => $this->adminEmail,
            'temporaryPassword' => '[redacted]',
        ];
    }
}
