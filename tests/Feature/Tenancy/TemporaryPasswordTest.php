<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('the tenant admin can log in with the temporary password on the tenant subdomain', function () {
    $result = provisionTenant('acme');

    $this->post($result->tenant->url('login'), [
        'email' => $result->adminEmail,
        'password' => $result->temporaryPassword,
    ])->assertSessionHasNoErrors()->assertRedirect('/dashboard');

    expect(auth('web')->user()->email)->toBe($result->adminEmail);
});

test('a user with a temporary password is forced to change it before continuing', function (string $path) {
    $tenant = actingInTenant();
    $admin = User::query()->where('is_tenant_admin', true)->sole();

    $this->actingAs($admin)
        ->get($tenant->url($path))
        ->assertRedirect(route('security.edit'));
})->with(['dashboard', 'settings/profile', 'settings/appearance']);

test('the password change page explains that the password is temporary', function () {
    actingInTenant();
    $admin = User::query()->where('is_tenant_admin', true)->sole();

    $this->actingAs($admin)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('You are using a temporary password');
});

test('changing the temporary password lifts the restriction', function () {
    $result = provisionTenant('acme');
    tenancy()->initialize($result->tenant);
    $admin = User::query()->where('is_tenant_admin', true)->sole();

    $this->actingAs($admin);

    Livewire::test('pages::settings.security')
        ->set('current_password', $result->temporaryPassword)
        ->set('password', 'a-brand-new-password')
        ->set('password_confirmation', 'a-brand-new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse()
        ->and(Hash::check('a-brand-new-password', $admin->password))->toBeTrue();

    $this->get($result->tenant->url('dashboard'))->assertOk();
});

test('livewire updates are refused while the user must change a temporary password', function () {
    $tenant = provisionTenant('acme')->tenant;
    $admin = $tenant->run(fn () => User::query()->where('is_tenant_admin', true)->sole());

    // A page loaded while the user was not restricted; then the user-wide flag is set (as for
    // a session that signed in with a temporary password the owner has not changed yet).
    loginToTenant($tenant, $admin);
    $html = $this->get($tenant->url('settings/profile'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $match);
    $tenant->run(fn () => $admin->forceFill(['must_change_password' => true])->save());

    // The next request reloads the user from the session, as a real request would.
    auth()->forgetGuards();

    $this->withHeaders(['X-Livewire' => '1'])->postJson(
        $tenant->url(route('livewire.update', absolute: false)),
        ['components' => [['snapshot' => html_entity_decode($match[1], ENT_QUOTES), 'updates' => ['name' => 'Renamed'], 'calls' => []]]],
    )->assertRedirect(route('security.edit'));

    expect($tenant->run(fn () => $admin->refresh()->name))->not->toBe('Renamed');
});
