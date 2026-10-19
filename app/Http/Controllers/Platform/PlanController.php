<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\SavePlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlanRequest;
use App\Models\PlatformAdmin;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('platform.plans.index', [
            'plans' => SubscriptionPlan::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'usage' => SubscriptionTerm::query()->selectRaw('subscription_plan_id, count(distinct tenant_subscription_id) as subscriptions')
                ->groupBy('subscription_plan_id')->pluck('subscriptions', 'subscription_plan_id'),
        ]);
    }

    public function create(): View
    {
        return view('platform.plans.form', ['plan' => null]);
    }

    public function store(PlanRequest $request, SavePlan $savePlan): RedirectResponse
    {
        $savePlan->create($this->admin($request), $request->plan());

        return redirect()->route('platform.plans.index')->with('status', __('Plan created.'));
    }

    public function edit(SubscriptionPlan $plan): View
    {
        return view('platform.plans.form', ['plan' => $plan]);
    }

    public function update(PlanRequest $request, SubscriptionPlan $plan, SavePlan $savePlan): RedirectResponse
    {
        $savePlan->update($this->admin($request), $plan, $request->plan());

        return redirect()->route('platform.plans.index')->with('status', __('Plan updated. Existing subscription terms keep their prices.'));
    }

    public function archive(Request $request, SubscriptionPlan $plan, SavePlan $savePlan): RedirectResponse
    {
        $savePlan->setActive($this->admin($request), $plan, false);

        return redirect()->route('platform.plans.index')->with('status', __('Plan archived. Existing subscriptions are not affected.'));
    }

    public function activate(Request $request, SubscriptionPlan $plan, SavePlan $savePlan): RedirectResponse
    {
        $savePlan->setActive($this->admin($request), $plan, true);

        return redirect()->route('platform.plans.index')->with('status', __('Plan activated.'));
    }

    protected function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }
}
