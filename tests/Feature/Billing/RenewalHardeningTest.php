<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RenewSubscription;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Enums\PlatformAuditAction;
use App\Enums\SeatRequestStatus;
use App\Enums\SeatRequestType;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SeatChangeRequest;
use App\Models\SubscriptionTerm;
use Carbon\CarbonImmutable;

// Milestone 5.2: a renewal is confirmed only against the term the admin previewed, and
// an approved reduction is recorded as applied only when it really was.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    $this->plan = testPlan(['name' => 'Standard']);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // Term 1: 2026-01-15 → 2027-01-15, 5 seats, 1,000.00 + 5 × 120.00.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, 5, CarbonImmutable::today());
});

function requestSeats(SeatRequestType $type, int $seats): SeatChangeRequest
{
    tenancy()->initialize(test()->tenant);
    $request = app(RequestSeatChange::class)->handle(tenantAdminUser(), $type, $seats);
    tenancy()->end();

    return $request;
}

/**
 * Submit the renewal form exactly as it was rendered by the preview.
 *
 * @param  array<string, string>  $form
 */
function submitRenewalForm(array $form)
{
    return test()->actingAs(test()->platformAdmin, 'platform')->post(route('platform.subscriptions.renew.store', test()->tenant), $form);
}

/**
 * The hidden fields of the renewal form as rendered now.
 *
 * @return array<string, string>
 */
function renderedRenewalForm(): array
{
    $html = test()->actingAs(test()->platformAdmin, 'platform')->get(route('platform.subscriptions.renew', test()->tenant))->assertOk()->getContent();
    preg_match_all('/<input type="hidden" name="([a-z_]+)" value="([^"]*)"/', (string) $html, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])->except('_token')->all();
}

test('the renewal form carries the previewed seats and reduction', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    expect(renderedRenewalForm())->toMatchArray(['seats' => '5', 'expected_total' => '160000', 'expected_previous_seats' => '5', 'expected_reduction_id' => '0']);
});

test('a renewal confirmed from a form opened before seats were bought is refused', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));
    $staleForm = renderedRenewalForm();

    // Meanwhile two seats are bought (and paid for) in the current term.
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, requestSeats(SeatRequestType::Increase, 2));

    submitRenewalForm($staleForm)->assertSessionHasErrors(['subscription' => 'The current term changed since the preview: it now has 7 seats. Review the renewal again.']);

    expect(SubscriptionTerm::query()->count())->toBe(1);

    // The fresh preview proposes the 7 seats, and confirming it works.
    $freshForm = renderedRenewalForm();
    expect($freshForm['seats'])->toBe('7');
    submitRenewalForm($freshForm)->assertSessionHasNoErrors();
    expect(SubscriptionTerm::query()->where('sequence', 2)->sole()->seats)->toBe(7);
});

test('a renewal confirmed from a form opened before a reduction was approved is refused', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));
    $staleForm = renderedRenewalForm();

    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, requestSeats(SeatRequestType::Reduction, 3));

    submitRenewalForm($staleForm)->assertSessionHasErrors(['subscription' => 'An approved seat reduction changed since the preview. Review the renewal again.']);

    expect(SubscriptionTerm::query()->count())->toBe(1);
});

test('an approved reduction is applied when the renewal uses its seats', function () {
    $reduction = requestSeats(SeatRequestType::Reduction, 3);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $reduction);
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    $term = app(RenewSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, 3, false);

    expect($reduction->refresh()->status)->toBe(SeatRequestStatus::Applied)
        ->and($reduction->applied_to_term_id)->toBe($term->id)
        ->and(PlatformAuditLog::query()->where('action', PlatformAuditAction::SubscriptionRenewed)->sole()->reason)->toBe("Applied approved seat reduction #{$reduction->id}");
});

test('an approved reduction is superseded, not applied, when the renewal uses other seats', function () {
    $reduction = requestSeats(SeatRequestType::Reduction, 3);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $reduction);
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    $term = app(RenewSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, 4, false);

    expect($term->seats)->toBe(4)
        ->and($reduction->refresh()->status)->toBe(SeatRequestStatus::Superseded)
        ->and($reduction->applied_to_term_id)->toBeNull()
        ->and(PlatformAuditLog::query()->where('action', PlatformAuditAction::SubscriptionRenewed)->sole()->reason)
        ->toBe("Approved seat reduction #{$reduction->id} to 3 seats superseded: renewed with 4 seats")
        ->and(BillingCharge::query()->count())->toBe(2);
});
