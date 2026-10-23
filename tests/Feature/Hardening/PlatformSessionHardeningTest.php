<?php

use App\Enums\ImpersonationEndReason;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

// Milestone 5.2: a change to a Platform Admin's login security ends what was opened
// before it (other browsers, and support sessions when the change weakens it).

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    $this->other = PlatformAdmin::factory()->create(['email' => 'other@platform.test']);
});

test('confirming two-factor authentication signs the admin out of their other browsers', function () {
    config(['session.driver' => 'database']);
    storeSessionRow('stolen-browser', $this->admin->id);
    storeSessionRow('colleague-browser', $this->other->id);

    $this->actingAs($this->admin, 'platform')->post(route('platform.account.two-factor.enable'), ['password' => 'password']);
    $secret = (string) $this->admin->fresh()->two_factor_secret;
    $engine = app(Google2FA::class);

    $this->post(route('platform.account.two-factor.confirm'), ['code' => $engine->oathTotp($secret, $engine->getTimestamp())])->assertOk();

    $remaining = DB::table('sessions')->whereNotNull('user_id')->pluck('id')->all();
    expect($remaining)->not->toContain('stolen-browser')
        ->and($remaining)->toContain('colleague-browser');
});

test('disabling two-factor authentication ends the admin\'s other browsers and open support sessions', function () {
    config(['session.driver' => 'database', 'platform.require_two_factor' => false]);
    enableSupportAccess(requireTwoFactor: false);
    enableTwoFactorFor($this->admin);
    $tenant = provisionTenant('acme')->tenant;
    [$session] = startSupportSession($this->admin, $tenant);
    storeSessionRow('stolen-browser', $this->admin->id);

    $this->actingAs($this->admin, 'platform')
        ->delete(route('platform.account.two-factor.disable'), ['password' => 'password', 'recovery_code' => 'recovery-code-one'])
        ->assertRedirect();

    expect($session->refresh()->end_reason)->toBe(ImpersonationEndReason::ActorUnauthorized)
        ->and(DB::table('sessions')->where('id', 'stolen-browser')->exists())->toBeFalse();
});

test('resetting two-factor authentication from the command line ends every browser and support session of the admin', function () {
    config(['session.driver' => 'database']);
    enableSupportAccess(requireTwoFactor: false);
    enableTwoFactorFor($this->admin);
    $tenant = provisionTenant('acme')->tenant;
    [$session] = startSupportSession($this->admin, $tenant);
    [$colleagueSession] = startSupportSession($this->other, $tenant);
    storeSessionRow('admin-browser', $this->admin->id);
    storeSessionRow('colleague-browser', $this->other->id);

    $this->artisan('platform:reset-two-factor', ['email' => 'ops@platform.test', '--force' => true])->assertSuccessful();

    expect($session->refresh()->end_reason)->toBe(ImpersonationEndReason::ActorUnauthorized)
        ->and($colleagueSession->refresh()->ended_at)->toBeNull()
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['colleague-browser']);
});
