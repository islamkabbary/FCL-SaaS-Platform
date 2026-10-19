<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Tenancy\ProvisionTenant;
use App\Exceptions\TenantProvisioningFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TenantController extends Controller
{
    /**
     * List all tenants with their provisioning status.
     */
    public function index(): View
    {
        return view('platform.tenants.index', [
            'tenants' => Tenant::query()->with('domains')->latest()->get(),
        ]);
    }

    /**
     * Show the form for creating a tenant.
     */
    public function create(): View
    {
        return view('platform.tenants.create');
    }

    /**
     * Create and provision a tenant.
     *
     * The provisioning result (including the Tenant Admin's temporary password) is
     * rendered directly in this response and never stored in the session, so it can
     * be seen exactly once. The response is marked as non-cacheable.
     */
    public function store(StoreTenantRequest $request, ProvisionTenant $provisionTenant): Response|RedirectResponse
    {
        try {
            $result = $provisionTenant->create([
                'company_name' => $request->string('company_name')->toString(),
                'subdomain' => $request->string('subdomain')->toString(),
                'admin_name' => $request->string('admin_name')->toString(),
                'admin_email' => $request->string('admin_email')->toString(),
            ]);
        } catch (TenantProvisioningFailed $exception) {
            report($exception);

            return redirect()->route('platform.tenants.index')
                ->with('error', __('Provisioning of ":name" failed. The tenant was marked as failed and can be retried with "php artisan tenants:provision :id".', [
                    'name' => $exception->tenant->name,
                    'id' => $exception->tenant->id,
                ]));
        }

        return response()
            ->view('platform.tenants.provisioned', [
                'result' => $result,
                'loginUrl' => $result->tenant->url('login'),
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
