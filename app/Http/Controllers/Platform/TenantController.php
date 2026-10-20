<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\UpdateTenantDetails;
use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\TenantAccessStatus;
use App\Enums\TenantStatus;
use App\Exceptions\TenantProvisioningFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreTenantRequest;
use App\Http\Requests\Platform\UpdateTenantRequest;
use App\Models\PlatformAdmin;
use App\Models\SupportSession;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Stancl\Tenancy\Exceptions\DomainOccupiedByOtherTenantException;

class TenantController extends Controller
{
    /**
     * List tenants from the central database, with search and filters.
     *
     * Unknown filter values are ignored rather than trusted.
     */
    public function index(Request $request): View
    {
        $search = Str::limit(trim($request->string('search')->toString()), 100, '');
        $status = TenantStatus::tryFrom($request->string('status')->toString());
        $access = TenantAccessStatus::tryFrom($request->string('access')->toString());

        $tenants = Tenant::query()
            ->with('domains')
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($status, fn ($query, TenantStatus $status) => $query->where('status', $status))
            ->when($access, fn ($query, TenantAccessStatus $access) => $query->whereAccessStatus($access))
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('platform.tenants.index', [
            'tenants' => $tenants,
            'filters' => ['search' => $search, 'status' => $status, 'access' => $access],
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
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        try {
            $result = $provisionTenant->create([
                'company_name' => $request->string('company_name')->toString(),
                'subdomain' => $request->string('subdomain')->toString(),
                'admin_name' => $request->string('admin_name')->toString(),
                'admin_email' => $request->string('admin_email')->toString(),
            ], $admin);
        } catch (DomainOccupiedByOtherTenantException|UniqueConstraintViolationException) {
            // The same subdomain was registered at the same moment, after this request was
            // validated: found by the domain model's own check, or refused by the unique index
            // when both requests passed that check. Nothing of this tenant was kept.
            return back()->withInput()->withErrors(['subdomain' => __('The subdomain has already been taken.')]);
        } catch (TenantProvisioningFailed $exception) {
            report($exception);

            return redirect()->route('platform.tenants.show', $exception->tenant)
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

    /**
     * Show a tenant's central record, lifecycle and latest administration history.
     *
     * Only central data is shown; the tenant database is never opened.
     */
    public function show(Tenant $tenant): View
    {
        return view('platform.tenants.show', [
            'tenant' => $tenant->load('domains'),
            'databaseName' => $tenant->database()->getName(),
            'recentActivity' => $tenant->auditLogs()->latest('created_at')->latest('id')->limit(5)->get(),
            'activityCount' => $tenant->auditLogs()->count(),
            'supportSessions' => SupportSession::query()->where('tenant_id', $tenant->getTenantKey())->latest('started_at')->latest('id')->limit(5)->get(),
        ]);
    }

    /**
     * Show the form for editing a tenant's administrative details.
     */
    public function edit(Tenant $tenant): View
    {
        return view('platform.tenants.edit', ['tenant' => $tenant->load('domains')]);
    }

    /**
     * Update a tenant's administrative details.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant, UpdateTenantDetails $updateTenantDetails): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        $updateTenantDetails->handle($admin, $tenant, $request->validated());

        return redirect()->route('platform.tenants.show', $tenant)
            ->with('status', __('Tenant details updated.'));
    }

    /**
     * Show the complete administration history of a tenant (read-only).
     */
    public function history(Tenant $tenant): View
    {
        return view('platform.tenants.history', [
            'tenant' => $tenant->load('domains'),
            'entries' => $tenant->auditLogs()->latest('created_at')->latest('id')->paginate(20),
        ]);
    }
}
