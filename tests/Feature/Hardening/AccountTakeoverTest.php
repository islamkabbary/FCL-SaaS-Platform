<?php

use App\Actions\Employees\UpdateEmployee;
use App\Enums\TenantPermission;
use App\Models\User;
use App\Notifications\EmailAddressChanged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Milestone 5.2: a changed login email receives the password reset link, so changing it
// must not let anyone quietly take over an account.

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
    Notification::fake();
});

test('an employee whose email is changed by a colleague is unverified, signed out everywhere and told at the old address', function () {
    config(['session.driver' => 'database']);
    $hr = userWithPermissions([TenantPermission::EmployeesView, TenantPermission::EmployeesUpdate]);
    $victim = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'victim@acme.test']);
    $rememberToken = $victim->remember_token;
    storeSessionRow('victim-laptop', $victim->id);
    storeSessionRow('hr-browser', $hr->id);

    $changed = app(UpdateEmployee::class)->handle($hr, $victim, ['name' => $victim->name, 'email' => 'New.Address@Acme.test']);

    $victim->refresh();
    expect($changed)->toBeTrue()
        ->and($victim->email)->toBe('new.address@acme.test')
        ->and($victim->email_verified_at)->toBeNull()
        ->and($victim->remember_token)->not->toBe($rememberToken)
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['hr-browser']);

    Notification::assertSentOnDemand(EmailAddressChanged::class, function (EmailAddressChanged $notification, array $channels, AnonymousNotifiable $notifiable) use ($hr) {
        return $notifiable->routes['mail'] === 'victim@acme.test'
            && $notification->newEmail === 'new.address@acme.test'
            && $notification->changedBy === $hr->name;
    });
});

test('editing only the name of an employee keeps their verification and sessions and sends nothing', function () {
    config(['session.driver' => 'database']);
    $victim = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'victim@acme.test']);
    storeSessionRow('victim-laptop', $victim->id);

    $changed = app(UpdateEmployee::class)->handle($this->admin, $victim, ['name' => 'Renamed', 'email' => 'VICTIM@acme.test']);

    expect($changed)->toBeFalse()
        ->and($victim->refresh()->email_verified_at)->not->toBeNull()
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['victim-laptop']);

    Notification::assertNothingSent();
});

test('the employees page tells the admin that a changed email signed the employee out', function () {
    $victim = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'victim@acme.test']);

    Livewire::actingAs($this->admin)
        ->test('pages::employees.index')
        ->call('edit', $victim->id)
        ->set('email', 'moved@acme.test')
        ->call('save')
        ->assertHasNoErrors();

    expect($victim->refresh()->email)->toBe('moved@acme.test');
    Notification::assertSentOnDemandTimes(EmailAddressChanged::class, 1);
});

test('changing your own email on the profile page needs your current password', function (?string $currentPassword) {
    $user = User::factory()->create(['email' => 'me@acme.test']);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('email', 'thief@evil.test')
        ->set('current_password', $currentPassword ?? '')
        ->call('updateProfileInformation')
        ->assertHasErrors(['current_password']);

    expect($user->refresh()->email)->toBe('me@acme.test');
    Notification::assertNothingSent();
})->with(['missing' => null, 'wrong' => 'not-my-password']);

test('with the current password the profile email changes and the old address is told', function () {
    $user = User::factory()->create(['email' => 'me@acme.test']);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('email', 'Me.New@acme.test')
        ->set('current_password', 'password')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertSet('current_password', '');

    $user->refresh();
    expect($user->email)->toBe('me.new@acme.test')
        ->and($user->email_verified_at)->toBeNull();

    Notification::assertSentOnDemand(EmailAddressChanged::class, fn (EmailAddressChanged $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'me@acme.test');
});

test('changing only the profile name needs no password', function () {
    $user = User::factory()->create(['email' => 'me@acme.test']);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('name', 'New Name')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->name)->toBe('New Name');
    Notification::assertNothingSent();
});
