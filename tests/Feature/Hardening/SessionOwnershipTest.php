<?php

use App\Actions\Auth\EndOtherSessions;
use App\Actions\Employees\SetEmployeeActivation;
use App\Enums\ImpersonationEndReason;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Milestone 5.2: during an impersonation the browser's session row belongs to whoever
// started it, so signing someone out everywhere affects the right browsers.

beforeEach(function () {
    useDatabaseSessions();
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
    $this->employee = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'clerk@acme.test']);
});

/**
 * The ids of the users the stored session rows belong to. (The test client sends no
 * cookie, so each request stores its own row; their owners are what matters here.)
 *
 * @return list<int>
 */
function sessionRowOwners(): array
{
    return DB::table('sessions')->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
}

function startViewInBrowser(): void
{
    loginToTenant(test()->tenant, test()->admin);
    startEmployeeView(test()->tenant, test()->employee->id)->assertOk();
    test()->get(test()->tenant->url('dashboard'))->assertOk()->assertSee('Impersonation active');
}

test('the browser of an employee view stays attributed to the tenant admin', function () {
    startViewInBrowser();

    expect(sessionRowOwners())->toBe([$this->admin->id]);
});

test('deactivating the viewed employee keeps the tenant admin\'s browser, which is then restored', function () {
    startViewInBrowser();
    $rows = DB::table('sessions')->count();

    $otherAdmin = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);
    app(SetEmployeeActivation::class)->handle($otherAdmin, $this->employee, false);

    // None of the rows belongs to the employee, so none of the admin's browser rows is deleted.
    expect(ImpersonationSession::query()->sole()->end_reason)->toBe(ImpersonationEndReason::TargetDeactivated)
        ->and(DB::table('sessions')->count())->toBe($rows);

    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('employees'));
    expect(auth('web')->id())->toBe($this->admin->id);
});

test('the employee signing out their other sessions does not end the admin\'s view', function () {
    startViewInBrowser();
    $rows = DB::table('sessions')->count();

    // For example the employee changes their password in their own browser.
    app(EndOtherSessions::class)->handle($this->employee->fresh());

    expect(DB::table('sessions')->count())->toBe($rows);
});

test('signing the tenant admin out everywhere also ends their impersonating browser', function () {
    startViewInBrowser();

    app(EndOtherSessions::class)->handle($this->admin->fresh());

    expect(sessionRowOwners())->toBe([]);
});

test('a support browser is not attributed to the tenant admin it acts as', function () {
    enableSupportAccess();
    tenancy()->end();
    $platformAdmin = PlatformAdmin::factory()->create();
    [, $token] = startSupportSession($platformAdmin, $this->tenant);
    postHandoff($this->tenant, $token)->assertRedirect();
    $this->get($this->tenant->url('dashboard'))->assertOk();

    $tenantAdminId = $this->admin->id;
    expect($this->tenant->run(fn (): bool => DB::table('sessions')->where('user_id', $tenantAdminId)->exists()))->toBeFalse();
});
