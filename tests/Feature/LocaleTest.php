<?php

use App\Enums\TenantRole;
use App\Http\Middleware\SetLocale;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Middleware\ScopeSessions;

describe('on a tenant', function () {
    beforeEach(function () {
        $this->tenant = actingInTenant();
    });

    test('a guest switches language and the page renders right to left', function () {
        $this->from(route('login'))->post(route('locale.switch'), ['locale' => 'ar'])
            ->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee('سجّل الدخول إلى حسابك');
    });

    test('the default language renders left to right', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('lang="en" dir="ltr"', false)
            ->assertSee('Log in to your account');
    });

    test('an unsupported language is refused', function () {
        $this->from(route('login'))->post(route('locale.switch'), ['locale' => 'fr'])
            ->assertSessionHasErrors('locale')
            ->assertSessionMissing(SetLocale::SessionKey);
    });

    test('validation messages use the active language', function () {
        $this->post(route('locale.switch'), ['locale' => 'ar']);

        $this->post(route('login.store'), ['email' => '', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'حقل البريد الإلكتروني مطلوب.']);
    });

    test('a user keeps their chosen language in a new session', function () {
        $user = tenantAdminUser();

        $this->actingAs($user)->post(route('locale.switch'), ['locale' => 'ar']);

        expect($user->refresh()->locale)->toBe('ar');

        $this->flushSession();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false);
    });

    test('a user preference comes before the session', function () {
        $user = tenantAdminUser();
        $user->forceFill(['locale' => 'en'])->save();

        $this->actingAs($user)
            ->withSession([SetLocale::SessionKey => 'ar'])
            ->get(route('dashboard'))
            ->assertSee('lang="en" dir="ltr"', false);
    });

    test('a language that is no longer supported falls back to the default', function () {
        $user = tenantAdminUser();
        $user->forceFill(['locale' => 'fr'])->save();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('lang="en" dir="ltr"', false);
    });

    test('a Livewire update keeps the language of the user', function () {
        $user = tenantAdminUser();
        $user->forceFill(['locale' => 'ar'])->save();
        tenancy()->end();

        loginToTenant($this->tenant, $user);

        $response = livewireRequest($this->tenant, 'employees', [['method' => '$refresh', 'params' => []]]);

        expect(data_get($response->assertOk()->json(), 'components.0.effects.html'))->toContain('موظف جديد');
    });

    test('built-in role names are translated and custom role names stay as entered', function () {
        Role::create(['name' => 'Customs Clerk', 'guard_name' => 'web']);
        $user = tenantAdminUser();
        $user->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($user)->get(route('roles.index'))
            ->assertOk()
            ->assertSee(TenantRole::displayName(TenantRole::TenantAdmin->value))
            ->assertSee('مسؤول الشركة')
            ->assertSee('Customs Clerk');
    });

    test('the language is read only after the session scope is verified', function () {
        $middleware = app(Router::class)->gatherRouteMiddleware(Route::getRoutes()->getByName('dashboard'));
        $position = fn (string $class): int|false => array_search($class, $middleware, true);

        expect($position(ScopeSessions::class))->toBeLessThan($position(SetLocale::class))
            ->and($position(SetLocale::class))->toBeLessThan($position(Authenticate::class));
    });
});

describe('during impersonation', function () {
    beforeEach(function () {
        enableSupportAccess();
        $this->tenant = provisionTenant('acme')->tenant;
        $this->targetId = $this->tenant->run(function (): int {
            $admin = tenantAdminUser();
            $admin->forceFill(['locale' => 'en'])->save();

            return $admin->id;
        });

        [, $token] = startSupportSession(PlatformAdmin::factory()->create(), $this->tenant, $this->targetId);
        postHandoff($this->tenant, $token)->assertRedirect();
    });

    test('the choice stays in the session and never changes the user', function () {
        $this->post($this->tenant->url('locale'), ['locale' => 'ar'])->assertRedirect();

        expect($this->tenant->run(fn (): ?string => User::query()->find($this->targetId)->locale))->toBe('en');

        $this->get($this->tenant->url('dashboard'))
            ->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false);
    });
});

describe('on the central domain', function () {
    test('a guest switches language', function () {
        $this->from(route('platform.login'))->post(route('central.locale.switch'), ['locale' => 'ar'])
            ->assertRedirect(route('platform.login'));

        $this->get(route('platform.login'))
            ->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false);
    });

    test('a Platform Admin keeps their chosen language', function () {
        $admin = PlatformAdmin::factory()->create();
        enableTwoFactorFor($admin);

        $this->actingAs($admin, 'platform')->post(route('central.locale.switch'), ['locale' => 'ar']);

        expect($admin->refresh()->locale)->toBe('ar');

        $this->flushSession();

        $this->actingAs($admin, 'platform')->get(route('platform.dashboard'))
            ->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false);
    });
});
