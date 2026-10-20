<?php

use App\Enums\TenantRole;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('livewire temporary uploads are refused on every host, even with a valid signature', function (string $host) {
    $tenant = provisionTenant('acme')->tenant;
    $base = $host === 'tenant' ? rtrim($tenant->url(), '/') : 'http://localhost';

    $upload = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);
    $preview = URL::temporarySignedRoute('livewire.preview-file', now()->addMinutes(5), ['filename' => 'secret.png'], absolute: false);

    $this->post($base.$upload, ['files' => [UploadedFile::fake()->image('a.png')]])->assertNotFound();
    $this->get($base.$preview)->assertNotFound();
})->with(['tenant', 'central']);

test('private local files are not served over HTTP', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();

    $this->get('http://localhost/storage/anything.txt')->assertNotFound();
});

test('responses carry baseline security headers', function (string $url) {
    $tenant = provisionTenant('acme')->tenant;
    $url = str_replace('{tenant}', rtrim($tenant->url(), '/'), $url);

    $this->get($url)
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
})->with([
    'central' => ['http://localhost/admin/login'],
    'tenant' => ['{tenant}/login'],
]);

test('HSTS is sent only over HTTPS', function () {
    $this->get('https://localhost/admin/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('the last active tenant admin cannot delete their own account', function () {
    actingInTenant();
    $admin = tenantAdminUser();
    $admin->forceFill(['password' => 'password'])->save();

    Livewire::actingAs($admin)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password' => 'The last active Tenant Admin cannot delete their account.']);

    expect($admin->fresh())->not->toBeNull();

    // With a second active Tenant Admin the account can be deleted.
    User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);
    Livewire::actingAs($admin)->test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasNoErrors();
    expect($admin->fresh())->toBeNull();
});

test('deleting their own account locks the active tenant admins before the last-admin check', function () {
    actingInTenant();
    $admin = tenantAdminUser();
    $admin->forceFill(['password' => 'password'])->save();
    User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);

    $first = firstQueriesOfTransactions(fn () => Livewire::actingAs($admin)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors());

    expect($first[0] ?? '')->toContain('for update')->toContain('`users`')
        ->and($admin->fresh())->toBeNull();
});

test('platform and tenant abilities deny the other kind of identity instead of failing', function () {
    $platformAdmin = PlatformAdmin::factory()->create();
    $tenant = actingInTenant();
    $tenantAdmin = tenantAdminUser();

    foreach (['manage-billing', 'start-support-session', 'view-support-sessions', 'revoke-support-session'] as $ability) {
        expect(Gate::forUser($tenantAdmin)->allows($ability))->toBeFalse();
    }

    expect(Gate::forUser($tenantAdmin)->allows('view', $tenant))->toBeFalse()
        ->and(Gate::forUser($tenantAdmin)->allows('suspend', $tenant))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('view-access-history'))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('impersonate', $tenantAdmin))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('viewAny', User::class))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('update', Role::query()->firstOrFail()))->toBeFalse()
        ->and(Gate::forUser($tenantAdmin)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('view', Tenant::query()->firstOrFail()))->toBeTrue();
});
