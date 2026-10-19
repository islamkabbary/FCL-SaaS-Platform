<?php

use App\Enums\PlatformAuditAction;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create();
});

test('platform admins can create an annual plan with prices in minor units', function () {
    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.plans.store'), [
            'name' => 'Standard',
            'description' => 'Every core feature',
            'currency' => 'kwd',
            'annual_base_fee' => '500.250',
            'annual_seat_price' => '30',
        ])
        ->assertRedirect(route('platform.plans.index'))
        ->assertSessionHasNoErrors();

    $plan = SubscriptionPlan::query()->sole();

    expect($plan->currency)->toBe('KWD')
        ->and($plan->annual_base_fee)->toBe(500250)
        ->and($plan->annual_seat_price)->toBe(30000)
        ->and($plan->is_active)->toBeTrue()
        ->and(PlatformAuditLog::query()->sole()->action)->toBe(PlatformAuditAction::PlanCreated);

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.plans.index'))
        ->assertOk()
        ->assertSee('Standard')
        ->assertSee('500.250 KWD')
        ->assertSee('30.000 KWD');
});

test('plan input is validated server-side', function (array $input, string $field) {
    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.plans.store'), [
            'name' => 'Standard',
            'currency' => 'USD',
            'annual_base_fee' => '100',
            'annual_seat_price' => '10',
            ...$input,
        ])
        ->assertSessionHasErrors($field);

    expect(SubscriptionPlan::query()->count())->toBe(0);
})->with([
    'unsupported currency' => [['currency' => 'XYZ'], 'currency'],
    'missing currency' => [['currency' => ''], 'currency'],
    'negative price' => [['annual_seat_price' => '-10'], 'annual_seat_price'],
    'too many decimals for the currency' => [['annual_base_fee' => '10.123'], 'annual_base_fee'],
    'not a number' => [['annual_base_fee' => 'ten'], 'annual_base_fee'],
    'too large' => [['annual_base_fee' => '999999999999'], 'annual_base_fee'],
    'missing name' => [['name' => ''], 'name'],
]);

test('editing a plan never rewrites the prices of existing terms', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    $plan = testPlan(['name' => 'Standard']);
    $term = subscribeTenant($tenant, seats: 5, plan: $plan);

    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.plans.update', $plan), [
            'name' => 'Standard 2027',
            'annual_base_fee' => '2000',
            'annual_seat_price' => '150.00',
        ])
        ->assertRedirect(route('platform.plans.index'));

    expect($plan->refresh()->annual_base_fee)->toBe(200000)
        ->and($plan->annual_seat_price)->toBe(15000)
        ->and($term->refresh()->annual_base_fee)->toBe(100000)
        ->and($term->annual_seat_price)->toBe(12000)
        ->and($term->plan_name)->toBe('Standard');

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::PlanUpdated)->sole();
    expect($entry->old_values)->toMatchArray(['annual_base_fee' => 100000, 'annual_seat_price' => 12000])
        ->and($entry->new_values)->toMatchArray(['annual_base_fee' => 200000, 'annual_seat_price' => 15000]);
});

test('the currency of an existing plan cannot be changed', function () {
    $plan = testPlan();

    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.plans.update', $plan), ['name' => $plan->name, 'currency' => 'EUR', 'annual_base_fee' => '1', 'annual_seat_price' => '1'])
        ->assertSessionHasErrors('currency');

    expect($plan->refresh()->currency)->toBe('USD');
});

test('archiving a plan keeps existing subscriptions valid and hides it from new activations', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    $plan = testPlan();
    subscribeTenant($tenant, plan: $plan);

    $this->actingAs($this->admin, 'platform')->post(route('platform.plans.archive', $plan))->assertRedirect();

    expect($plan->refresh()->is_active)->toBeFalse()
        ->and($tenant->subscriptionStatus()->value)->toBe('active')
        ->and(SubscriptionTerm::query()->where('subscription_plan_id', $plan->id)->count())->toBe(1);

    $this->actingAs($this->admin, 'platform')->post(route('platform.plans.activate', $plan))->assertRedirect();
    expect($plan->refresh()->is_active)->toBeTrue()
        ->and(PlatformAuditLog::query()->orderBy('id')->pluck('action')->map->value->all())->toBe(['plan.archived', 'plan.activated']);
});

test('subscription and billing administration is reserved to platform admins', function (string $method, string $route, array $parameters) {
    $this->{$method}(route($route, $parameters))->assertRedirect(route('platform.login'));
})->with([
    ['get', 'platform.plans.index', []],
    ['post', 'platform.plans.store', []],
    ['get', 'platform.subscriptions.index', []],
    ['get', 'platform.seat-requests.index', []],
    ['get', 'platform.charges.index', []],
]);

test('central plans cannot be reached or changed from a tenant subdomain', function () {
    $tenant = actingInTenant();
    $plan = testPlan();
    $this->actingAs(tenantAdminUser(), 'web');

    $this->get($tenant->url('admin/plans'))->assertNotFound();
    $this->put($tenant->url("admin/plans/{$plan->id}"), ['name' => 'Hacked', 'annual_base_fee' => '0', 'annual_seat_price' => '0'])->assertNotFound();

    expect($plan->refresh()->annual_seat_price)->toBe(12000);
});

test('the plan forms render for creating and editing', function () {
    $plan = testPlan(['name' => 'Standard', 'currency' => 'BHD', 'annual_base_fee' => 1500, 'annual_seat_price' => 250]);

    $this->actingAs($this->admin, 'platform')->get(route('platform.plans.create'))->assertOk()->assertSee('KWD (3 decimals)');
    $this->actingAs($this->admin, 'platform')->get(route('platform.plans.edit', $plan))->assertOk()
        ->assertSee('value="1.500"', false)
        ->assertSee('value="0.250"', false)
        ->assertSee('The currency of a plan cannot be changed.');
});
