<?php

use App\Actions\Employees\SetEmployeeActivation;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
    $this->employee = User::factory()->create(['email' => 'emp@acme.test'])->assignRole(TenantRole::Employee->value);
});

test('a deactivated employee loses their active session on the next request', function () {
    $this->post(route('login.store'), ['email' => 'emp@acme.test', 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->get(route('dashboard'))->assertOk();

    app(SetEmployeeActivation::class)->handle($this->admin, $this->employee, false);

    // The next request reloads the user from the session, as a real request would.
    auth()->forgetGuards();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account has been deactivated.');

    $this->assertGuest('web');
});

test('deactivation deletes the employee\'s stored sessions and rotates the remember token', function () {
    config(['session.driver' => 'database']);
    $rememberToken = $this->employee->remember_token;

    DB::table('sessions')->insert([
        ['id' => 'employee-session', 'user_id' => $this->employee->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'admin-session', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    app(SetEmployeeActivation::class)->handle($this->admin, $this->employee, false);

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['admin-session'])
        ->and($this->employee->refresh()->remember_token)->not->toBe($rememberToken);
});

test('a deactivated employee cannot log in', function () {
    app(SetEmployeeActivation::class)->handle($this->admin, $this->employee, false);

    $this->post(route('login.store'), ['email' => 'emp@acme.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been deactivated.']);

    $this->assertGuest('web');
});

test('a wrong password does not reveal that an account is deactivated', function () {
    app(SetEmployeeActivation::class)->handle($this->admin, $this->employee, false);

    $this->post(route('login.store'), ['email' => 'emp@acme.test', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
});

test('a reactivated employee can log in again', function () {
    app(SetEmployeeActivation::class)->handle($this->admin, $this->employee, false);
    app(SetEmployeeActivation::class)->handle($this->admin, $this->employee, true);

    $this->post(route('login.store'), ['email' => 'emp@acme.test', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($this->employee->refresh(), 'web');
});
