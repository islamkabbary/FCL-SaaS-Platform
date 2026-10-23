<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RenewSubscription;
use App\Billing\ActiveUsers;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionChange;
use App\Http\Controllers\Controller;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\SeatChangeRequest;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Tenant subscriptions: listing, details, manual activation and annual renewal.
 */
class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->string('status')->toString();
        $today = CarbonImmutable::today()->toDateString();
        $renewalDue = CarbonImmutable::today()->addDays((int) config('billing.renewal_window_days'))->toDateString();
        $latestEnd = '(select max(t.ends_on) from subscription_terms as t where t.tenant_subscription_id = tenant_subscriptions.id)';

        $tenants = Tenant::query()
            ->with(['domains', 'subscription.terms'])
            ->when($filter === 'active', fn (Builder $query) => $query->whereHas('subscription.terms', fn (Builder $terms) => $terms->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>', $today)))
            ->when($filter === 'expired', fn (Builder $query) => $query->has('subscription')->whereDoesntHave('subscription.terms', fn (Builder $terms) => $terms->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>', $today)))
            ->when($filter === 'renewal_due', fn (Builder $query) => $query->whereHas('subscription', fn (Builder $subscription) => $subscription->whereRaw("{$latestEnd} <= ?", [$renewalDue])))
            ->when($filter === 'none', fn (Builder $query) => $query->doesntHave('subscription')->where('subscription_required', true))
            ->when($filter === 'legacy', fn (Builder $query) => $query->doesntHave('subscription')->where('subscription_required', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('platform.subscriptions.index', ['tenants' => $tenants, 'filter' => $filter]);
    }

    public function show(Tenant $tenant, ActiveUsers $activeUsers): View
    {
        $subscription = TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->first();

        return view('platform.subscriptions.show', [
            'tenant' => $tenant->load('domains'),
            'status' => $tenant->subscriptionStatus(),
            'subscription' => $subscription,
            'currentTerm' => $subscription?->termOn(),
            'terms' => SubscriptionTerm::query()->where('tenant_id', $tenant->getTenantKey())->orderByDesc('sequence')->get(),
            'seatLimit' => $tenant->seatLimit(),
            'activeUsers' => $this->safeCount($tenant, $activeUsers),
            'charges' => BillingCharge::query()->where('tenant_id', $tenant->getTenantKey())->withPaidTotal()->latest('issued_on')->latest('id')->get(),
            'requests' => SeatChangeRequest::query()->where('tenant_id', $tenant->getTenantKey())->latest()->latest('id')->limit(10)->get(),
            'scheduledReduction' => SeatChangeRequest::query()->where('tenant_id', $tenant->getTenantKey())->scheduledReduction()->first(),
        ]);
    }

    /**
     * Activation form; with plan, seats and start date in the query string it also
     * shows the preview of the term and the annual charge.
     */
    public function createActivation(Request $request, Tenant $tenant, ActivateSubscription $activateSubscription): View|RedirectResponse
    {
        if (TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->exists()) {
            return redirect()->route('platform.subscriptions.show', $tenant)->with('error', __('This tenant already has a subscription. Use renewal instead.'));
        }

        $input = $request->validate([
            'plan_id' => ['nullable', 'integer'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:'.config('billing.max_seats')],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $plan = isset($input['plan_id']) ? SubscriptionPlan::query()->active()->whereKey($input['plan_id'])->first() : null;
        $quote = $plan !== null && isset($input['seats'], $input['starts_on'])
            ? $activateSubscription->quote($tenant, $plan, (int) $input['seats'], CarbonImmutable::parse($input['starts_on']))
            : null;

        return view('platform.subscriptions.activate', [
            'tenant' => $tenant->load('domains'),
            'plans' => SubscriptionPlan::query()->active()->orderBy('name')->get(),
            'plan' => $plan,
            'seats' => $input['seats'] ?? null,
            'startsOn' => $input['starts_on'] ?? CarbonImmutable::today()->toDateString(),
            'quote' => $quote,
        ]);
    }

    public function activate(Request $request, Tenant $tenant, ActivateSubscription $activateSubscription): RedirectResponse
    {
        $input = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'seats' => ['required', 'integer', 'min:1', 'max:'.config('billing.max_seats')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'expected_total' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $activateSubscription->handle(
                $this->admin($request),
                $tenant,
                SubscriptionPlan::query()->whereKey($input['plan_id'])->firstOrFail(),
                (int) $input['seats'],
                CarbonImmutable::parse($input['starts_on']),
                (int) $input['expected_total'],
            );
        } catch (InvalidSubscriptionChange $exception) {
            return back()->withInput()->withErrors(['subscription' => $exception->getMessage()]);
        }

        return redirect()->route('platform.subscriptions.show', $tenant)->with('status', __('Subscription activated and the initial annual charge created.'));
    }

    /**
     * Renewal preview; plan and seats can be adjusted through the query string.
     */
    public function createRenewal(Request $request, Tenant $tenant, RenewSubscription $renewSubscription): View|RedirectResponse
    {
        $input = $request->validate([
            'plan_id' => ['nullable', 'integer'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:'.config('billing.max_seats')],
        ]);

        try {
            $quote = $renewSubscription->quote(
                $tenant,
                isset($input['plan_id']) ? SubscriptionPlan::query()->whereKey($input['plan_id'])->first() : null,
                isset($input['seats']) ? (int) $input['seats'] : null,
            );
        } catch (InvalidSubscriptionChange $exception) {
            return redirect()->route('platform.subscriptions.show', $tenant)->with('error', $exception->getMessage());
        }

        return view('platform.subscriptions.renew', [
            'tenant' => $tenant->load('domains'),
            'quote' => $quote,
            'plans' => SubscriptionPlan::query()->active()->where('currency', $quote->previousTerm->currency)->orderBy('name')->get(),
        ]);
    }

    public function renew(Request $request, Tenant $tenant, RenewSubscription $renewSubscription): RedirectResponse
    {
        $input = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'seats' => ['required', 'integer', 'min:1', 'max:'.config('billing.max_seats')],
            'accept_pricing_change' => ['nullable', 'boolean'],
            'expected_total' => ['required', 'integer', 'min:0'],
            'expected_previous_seats' => ['required', 'integer', 'min:1'],
            'expected_reduction_id' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $renewSubscription->handle(
                $this->admin($request),
                $tenant,
                SubscriptionPlan::query()->whereKey($input['plan_id'])->firstOrFail(),
                (int) $input['seats'],
                (bool) ($input['accept_pricing_change'] ?? false),
                (int) $input['expected_total'],
                (int) $input['expected_previous_seats'],
                (int) $input['expected_reduction_id'],
            );
        } catch (InvalidSubscriptionChange $exception) {
            return back()->withInput()->withErrors(['subscription' => $exception->getMessage()]);
        }

        return redirect()->route('platform.subscriptions.show', $tenant)->with('status', __('Subscription renewed and the annual renewal charge created.'));
    }

    /**
     * Active users of a ready tenant; null when the tenant database cannot be read.
     */
    protected function safeCount(Tenant $tenant, ActiveUsers $activeUsers): ?int
    {
        if (! $tenant->isReady()) {
            return null;
        }

        try {
            return $activeUsers->count($tenant);
        } catch (Throwable $exception) {
            report($exception);
            DB::purge('tenant');

            return null;
        }
    }

    protected function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }

    /**
     * Subscription status values offered as list filters.
     *
     * @return array<string, string>
     */
    public static function filters(): array
    {
        return [
            'active' => SubscriptionStatus::Active->label(),
            'renewal_due' => __('Renewal due or overdue'),
            'expired' => SubscriptionStatus::Expired->label(),
            'none' => SubscriptionStatus::None->label(),
            'legacy' => SubscriptionStatus::Legacy->label(),
        ];
    }
}
