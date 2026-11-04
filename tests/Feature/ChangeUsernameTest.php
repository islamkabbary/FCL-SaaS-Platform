<?php

use App\Actions\Users\ChangeUsername;
use App\Enums\AccountAuditAction;
use App\Enums\TenantRole;
use App\Impersonation\ActiveImpersonation;
use App\Models\PlatformAdmin;
use App\Models\TenantAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->user = User::factory()->create(['name' => 'Sara Ali'])->assignRole(TenantRole::Employee->value);
});

function changeUsernameOnProfile(string $username, string $password = 'password'): mixed
{
    return Livewire::test('pages::settings.profile')
        ->set('username', $username)
        ->set('username_password', $password)
        ->call('changeUsername');
}

test('a user changes the generated username, and the change is audited', function () {
    $this->actingAs($this->user);

    changeUsernameOnProfile('Sara_2026')->assertHasNoErrors();

    $this->user->refresh();

    expect($this->user->username)->toBe('sara_2026')
        ->and($this->user->username_changed_at)->not->toBeNull();

    $audit = TenantAuditLog::query()->where('action', AccountAuditAction::UsernameChanged->value)->sole();

    expect($audit->old_values)->toBe(['username' => 'sara.ali'])
        ->and($audit->new_values)->toBe(['username' => 'sara_2026']);
});

test('the new username signs in and the old one no longer does', function () {
    $this->actingAs($this->user);
    changeUsernameOnProfile('sara.new')->assertHasNoErrors();
    auth()->guard('web')->logout();

    $this->post(route('login.store'), ['login' => 'sara.ali', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->post(route('login.store'), ['login' => 'sara.new', 'password' => 'password'])
        ->assertSessionHasNoErrors();
});

test('a second change is refused and the form is replaced', function () {
    $this->actingAs($this->user);
    changeUsernameOnProfile('sara.new')->assertHasNoErrors();

    changeUsernameOnProfile('sara.again')->assertHasErrors(['username' => __('The username can only be changed once.')]);

    expect($this->user->refresh()->username)->toBe('sara.new');

    $this->get(route('profile.edit'))
        ->assertSee('data-test="username-locked"', false)
        ->assertDontSee('data-test="username-form"', false);
});

test('a wrong password changes nothing', function () {
    $this->actingAs($this->user);

    changeUsernameOnProfile('sara.new', 'wrong-password')->assertHasErrors('username_password');

    expect($this->user->refresh()->username)->toBe('sara.ali')
        ->and($this->user->username_changed_at)->toBeNull();
});

test('an invalid or taken username is refused without using the change', function (string $username) {
    User::factory()->create(['name' => 'Taken Name']);
    $this->actingAs($this->user);

    changeUsernameOnProfile($username)->assertHasErrors('username');

    expect($this->user->refresh()->username_changed_at)->toBeNull();
})->with([
    'taken' => 'taken.name',
    'own current username' => 'sara.ali',
    'an email' => 'sara@acme.test',
    'starts with a digit' => '1sara',
    'too short' => 'ab',
    'too long' => 'abcdefghijklmnopqrstuvwxyz12345',
    'spaces' => 'sara ali',
    'arabic' => 'سارة',
]);

test('only the owner may change a username', function () {
    $admin = tenantAdminUser();

    expect(Gate::forUser($this->user)->allows('changeUsername', $this->user))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('changeUsername', $this->user))->toBeFalse();
});

test('the same username can exist in two companies', function () {
    tenancy()->end();
    $globex = provisionTenant('globex')->tenant;
    $globex->run(fn () => User::factory()->create(['name' => 'Free Name']));
    tenancy()->initialize($this->tenant);
    $this->actingAs($this->user);

    changeUsernameOnProfile('free.name')->assertHasNoErrors();
});

test('an employee is told their generated username when they are created', function () {
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::employees.index')
        ->set('name', 'New Person')
        ->set('email', 'new@acme.test')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('createdEmployeeUsername', 'new.person');
});

test('the employee list can be searched by username', function () {
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::employees.index')
        ->set('search', 'sara.al')
        ->assertSee('Sara Ali');
});

test('a username change is refused during a support session, in every layer', function () {
    $target = tenantAdminUser();
    tenancy()->end();
    enableSupportAccess();

    [$session, $token] = startSupportSession(PlatformAdmin::factory()->create(), $this->tenant, $target->id);
    postHandoff($this->tenant, $token)->assertRedirect();

    livewireRequest($this->tenant, 'settings/profile', [['method' => 'changeUsername', 'params' => []]], [
        'username' => 'support.name',
        'username_password' => 'password',
    ])->assertForbidden();

    tenancy()->initialize($this->tenant);
    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));

    expect(fn () => app(ChangeUsername::class)->handle($target, 'support.name'))
        ->toThrow(AuthorizationException::class);

    expect($target->refresh()->username)->not->toBe('support.name')
        ->and($target->username_changed_at)->toBeNull();
});
