<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show tenant statistics and the latest platform administration activity.
     *
     * Every number comes from the central tenants table in a single query; no tenant
     * database is opened.
     */
    public function __invoke(): View
    {
        $counts = Tenant::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND suspended_at IS NULL THEN 1 ELSE 0 END), 0) AS active', [TenantStatus::Ready->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN suspended_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS suspended')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS pending', [TenantStatus::Pending->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS provisioning', [TenantStatus::Provisioning->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS failed', [TenantStatus::Failed->value])
            ->first();

        return view('platform.dashboard', [
            'stats' => [
                'total' => (int) ($counts->total ?? 0),
                'active' => (int) ($counts->active ?? 0),
                'suspended' => (int) ($counts->suspended ?? 0),
                'pending' => (int) ($counts->pending ?? 0),
                'provisioning' => (int) ($counts->provisioning ?? 0),
                'failed' => (int) ($counts->failed ?? 0),
            ],
            'recentActivity' => PlatformAuditLog::query()
                ->with('tenant.domains')
                ->latest('created_at')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
