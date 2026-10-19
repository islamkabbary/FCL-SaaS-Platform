<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ReactivateTenant;
use App\Actions\Platform\SuspendTenant;
use App\Exceptions\InvalidTenantTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ReactivateTenantRequest;
use App\Http\Requests\Platform\SuspendTenantRequest;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The tenant access lifecycle: suspending and reactivating a tenant, each behind a
 * confirmation page.
 */
class TenantAccessController extends Controller
{
    /**
     * Ask for confirmation and a reason before suspending a tenant.
     */
    public function confirmSuspension(Tenant $tenant): View|RedirectResponse
    {
        if (! $tenant->canBeSuspended()) {
            return $this->rejected($tenant, InvalidTenantTransition::cannotSuspend($tenant));
        }

        return view('platform.tenants.suspend', ['tenant' => $tenant->load('domains')]);
    }

    /**
     * Suspend a tenant's access.
     */
    public function suspend(SuspendTenantRequest $request, Tenant $tenant, SuspendTenant $suspendTenant): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        try {
            $suspendTenant->handle($admin, $tenant, $request->string('reason')->toString());
        } catch (InvalidTenantTransition $exception) {
            return $this->rejected($tenant, $exception);
        }

        return redirect()->route('platform.tenants.show', $tenant)
            ->with('status', __('The tenant has been suspended. Its users can no longer access the application.'));
    }

    /**
     * Ask for confirmation before reactivating a tenant.
     */
    public function confirmReactivation(Tenant $tenant): View|RedirectResponse
    {
        if (! $tenant->canBeReactivated()) {
            return $this->rejected($tenant, InvalidTenantTransition::cannotReactivate());
        }

        return view('platform.tenants.reactivate', ['tenant' => $tenant->load('domains')]);
    }

    /**
     * Reactivate a suspended tenant.
     */
    public function reactivate(ReactivateTenantRequest $request, Tenant $tenant, ReactivateTenant $reactivateTenant): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        try {
            $reactivateTenant->handle($admin, $tenant, $request->filled('reason') ? $request->string('reason')->toString() : null);
        } catch (InvalidTenantTransition $exception) {
            return $this->rejected($tenant, $exception);
        }

        return redirect()->route('platform.tenants.show', $tenant)
            ->with('status', __('The tenant has been reactivated. Its users can access the application again.'));
    }

    protected function rejected(Tenant $tenant, InvalidTenantTransition $exception): RedirectResponse
    {
        return redirect()->route('platform.tenants.show', $tenant)->with('error', $exception->getMessage());
    }
}
