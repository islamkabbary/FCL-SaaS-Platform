<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingCharge;
use App\Models\PlatformAuditLog;
use App\Models\SeatChangeRequest;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
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
            'billing' => $this->billingStats(),
            'recentActivity' => PlatformAuditLog::query()
                ->with('tenant.domains')
                ->latest('created_at')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * Subscription and billing figures from central records only.
     *
     * @return array{active: int, expired: int, renewal_due: int, pending_requests: int, outstanding: array<string, int>}
     */
    protected function billingStats(): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $renewalDue = CarbonImmutable::today()->addDays((int) config('billing.renewal_window_days'))->toDateString();
        $latestEnd = '(select max(t.ends_on) from subscription_terms as t where t.tenant_subscription_id = tenant_subscriptions.id)';
        $covering = fn ($terms) => $terms->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>', $today);

        $outstanding = BillingCharge::query()
            ->toBase()
            ->selectRaw('currency, sum(amount - (select coalesce(sum(p.amount), 0) from billing_payments as p where p.billing_charge_id = billing_charges.id)) as balance')
            ->groupBy('currency')
            ->orderBy('currency')
            ->pluck('balance', 'currency')
            ->map(fn ($balance): int => (int) $balance)
            ->filter()
            ->all();

        return [
            'active' => TenantSubscription::query()->whereHas('terms', $covering)->count(),
            'expired' => TenantSubscription::query()->whereDoesntHave('terms', $covering)->count(),
            'renewal_due' => TenantSubscription::query()->whereHas('terms', $covering)->whereRaw("{$latestEnd} <= ?", [$renewalDue])->count(),
            'pending_requests' => SeatChangeRequest::query()->pending()->count(),
            'outstanding' => $outstanding,
        ];
    }
}
