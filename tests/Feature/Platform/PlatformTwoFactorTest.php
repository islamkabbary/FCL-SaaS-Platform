<?php

use App\Enums\PlatformAuditAction;
use App\Http\Controllers\Platform\AuthenticatedSessionController;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
});

/**
 * A valid code for the given time step offset (0 = now, -1 = previous 30-second step).
 */
function totp(string $secret, int $offset = 0): string
{
    $engine = app(Google2FA::class);

    return $engine->oathTotp($secret, $engine->getTimestamp() + $offset);
}

function passwordStep(): TestResponse
{
    return test()->post(route('platform.login.store'), ['email' => 'ops@platform.test', 'password' => 'password']);
}

test('without two-factor the password logs in directly, and platform logins are never remembered', function () {
    $this->get(route('platform.login'))->assertOk()->assertDontSee('Remember me');

    $this->post(route('platform.login.store'), ['email' => 'ops@platform.test', 'password' => 'password', 'remember' => 'on'])
        ->assertRedirect(route('platform.dashboard'))
        ->assertCookieMissing(Auth::guard('platform')->getRecallerName());

    $this->assertAuthenticatedAs($this->admin, 'platform');
});

test('an admin enrolls with their password and a code, and sees recovery codes once', function () {
    $this->actingAs($this->admin, 'platform')->get(route('platform.account.security'))
        ->assertOk()
        ->assertSee('Not enabled');

    $this->actingAs($this->admin, 'platform')->post(route('platform.account.two-factor.enable'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($this->admin->fresh()->two_factor_secret)->toBeNull();

    $this->actingAs($this->admin, 'platform')->post(route('platform.account.two-factor.enable'), ['password' => 'password'])->assertRedirect();
    $secret = $this->admin->fresh()->two_factor_secret;

    $this->actingAs($this->admin, 'platform')->get(route('platform.account.security'))
        ->assertSee('Scan this QR code')
        ->assertSee($secret)
        ->assertSee('<svg', false);

    $this->actingAs($this->admin, 'platform')->post(route('platform.account.two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    expect($this->admin->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $response = $this->actingAs($this->admin, 'platform')->post(route('platform.account.two-factor.confirm'), ['code' => totp($secret)])
        ->assertOk()
        ->assertSee('These codes are shown only once.');

    $admin = $this->admin->fresh();
    expect($admin->hasTwoFactorEnabled())->toBeTrue()
        ->and($admin->two_factor_recovery_codes)->toHaveCount(8)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    foreach ($admin->two_factor_recovery_codes as $code) {
        $response->assertSee($code);
    }

    expect(PlatformAuditLog::query()->sole()->action)->toBe(PlatformAuditAction::TwoFactorEnabled);
});

test('the secret and recovery codes are encrypted at rest', function () {
    $secret = enableTwoFactorFor($this->admin);

    $row = DB::table('platform_admins')->where('id', $this->admin->id)->first();

    expect($row->two_factor_secret)->not->toBe($secret)
        ->and($row->two_factor_secret)->not->toContain($secret)
        ->and($row->two_factor_recovery_codes)->not->toContain('recovery-code-one')
        ->and($this->admin->fresh()->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);
});

test('with two-factor the password alone does not log in', function () {
    enableTwoFactorFor($this->admin);

    passwordStep()->assertRedirect(route('platform.two-factor.challenge'));

    $this->assertGuest('platform');
    $this->get(route('platform.dashboard'))->assertRedirect(route('platform.login'));
});

test('a valid code completes the login', function () {
    $secret = enableTwoFactorFor($this->admin);
    passwordStep();

    $this->get(route('platform.two-factor.challenge'))->assertOk()->assertSee('Authentication code');
    $this->post(route('platform.two-factor.challenge.store'), ['code' => totp($secret)])->assertRedirect(route('platform.dashboard'));

    $this->assertAuthenticatedAs($this->admin, 'platform');
});

test('a code cannot be used twice, and an older code is refused once a newer one was used', function () {
    $secret = enableTwoFactorFor($this->admin);

    passwordStep();
    $this->post(route('platform.two-factor.challenge.store'), ['code' => totp($secret)])->assertRedirect(route('platform.dashboard'));
    $this->post(route('platform.logout'));

    passwordStep();
    $this->post(route('platform.two-factor.challenge.store'), ['code' => totp($secret)])->assertSessionHasErrors('code');
    $this->post(route('platform.two-factor.challenge.store'), ['code' => totp($secret, -1)])->assertSessionHasErrors('code');
    $this->assertGuest('platform');
});

test('a recovery code works exactly once', function () {
    enableTwoFactorFor($this->admin);

    passwordStep();
    $this->post(route('platform.two-factor.challenge.store'), ['recovery_code' => 'recovery-code-one'])->assertRedirect(route('platform.dashboard'));
    expect($this->admin->fresh()->two_factor_recovery_codes)->toBe(['recovery-code-two']);
    $this->post(route('platform.logout'));

    passwordStep();
    $this->post(route('platform.two-factor.challenge.store'), ['recovery_code' => 'recovery-code-one'])->assertSessionHasErrors('code');
    $this->assertGuest('platform');
});

test('too many invalid codes discard the pending login', function () {
    $secret = enableTwoFactorFor($this->admin);
    passwordStep();

    foreach (range(1, 4) as $attempt) {
        $this->post(route('platform.two-factor.challenge.store'), ['code' => '000000'])->assertSessionHasErrors('code');
    }

    $this->post(route('platform.two-factor.challenge.store'), ['code' => '000000'])->assertRedirect(route('platform.login'));
    $this->post(route('platform.two-factor.challenge.store'), ['code' => totp($secret)])->assertRedirect(route('platform.login'));
    $this->assertGuest('platform');
});

test('a pending login expires after five minutes', function () {
    $secret = enableTwoFactorFor($this->admin);
    passwordStep();

    expect(session(AuthenticatedSessionController::PendingTwoFactorKey))->toBeArray();
    $this->travel(6)->minutes();

    $this->get(route('platform.two-factor.challenge'))->assertRedirect(route('platform.login'));
    $this->post(route('platform.two-factor.challenge.store'), ['code' => totp($secret)])->assertRedirect(route('platform.login'));
    $this->assertGuest('platform');
});

test('disabling requires the password and a current code', function () {
    $secret = enableTwoFactorFor($this->admin);

    $this->actingAs($this->admin, 'platform')->delete(route('platform.account.two-factor.disable'), ['password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($this->admin, 'platform')->delete(route('platform.account.two-factor.disable'), ['password' => 'wrong', 'code' => totp($secret)])->assertSessionHasErrors('password');
    expect($this->admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($this->admin, 'platform')->delete(route('platform.account.two-factor.disable'), ['password' => 'password', 'code' => totp($secret)])->assertRedirect();

    expect($this->admin->fresh()->two_factor_secret)->toBeNull()
        ->and(PlatformAuditLog::query()->sole()->action)->toBe(PlatformAuditAction::TwoFactorDisabled);
});

test('recovery codes can be regenerated with the password and a code', function () {
    $secret = enableTwoFactorFor($this->admin);

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.account.two-factor.recovery-codes'), ['password' => 'password', 'code' => totp($secret)])
        ->assertOk()
        ->assertSee('These codes are shown only once.');

    expect($this->admin->fresh()->two_factor_recovery_codes)->toHaveCount(8)->not->toContain('recovery-code-one');
});

test('an operator can reset a lost authenticator from the console, which is audited', function () {
    enableTwoFactorFor($this->admin);

    $this->artisan('platform:reset-two-factor', ['email' => 'OPS@platform.test', '--force' => true])->assertSuccessful();
    $this->artisan('platform:reset-two-factor', ['email' => 'nobody@platform.test', '--force' => true])->assertFailed();

    $entry = PlatformAuditLog::query()->sole();
    expect($this->admin->fresh()->two_factor_secret)->toBeNull()
        ->and($entry->action)->toBe(PlatformAuditAction::TwoFactorReset)
        ->and($entry->actor_type)->toBe(PlatformAuditLog::ActorSystem)
        ->and($entry->new_values)->toBe(['admin' => 'ops@platform.test']);

    passwordStep()->assertRedirect(route('platform.dashboard'));
});
