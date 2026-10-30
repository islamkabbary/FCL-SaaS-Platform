<?php

use App\Actions\Employees\SetEmployeeActivation;
use App\Actions\Platform\SuspendTenant;
use App\Enums\ImpersonationEndReason;
use App\Enums\ImpersonationStatus;
use App\Enums\PlatformAuditAction;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Exceptions\ImpersonationRefused;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionTerm;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SupportAccessStarted;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    enableSupportAccess();
    $this->admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    $this->tenant = provisionTenant('acme')->tenant;
    $this->tenantAdminId = $this->tenant->run(fn (): int => User::role(TenantRole::TenantAdmin->value)->firstOrFail()->id);
});

/**
 * A second, active Tenant Admin in the tenant.
 */
function secondTenantAdmin(Tenant $tenant, string $email = 'second@acme.test', bool $active = true): User
{
    return $tenant->run(fn (): User => User::factory()->create(['email' => $email, 'is_active' => $active])->assignRole(TenantRole::TenantAdmin->value));
}

/**
 * Start a support session over HTTP and return the handoff credential rendered in the page.
 */
function startSupportOverHttp(PlatformAdmin $admin, Tenant $tenant, int $targetUserId, array $overrides = []): string
{
    $html = test()->actingAs($admin, 'platform')
        ->post(route('platform.support-sessions.store', $tenant), [
            'target_user_id' => $targetUserId,
            'reason' => 'Ticket 4711: dashboard does not load',
            'password' => 'password',
            ...$overrides,
        ])
        ->assertOk()
        ->getContent();

    preg_match('/name="token" value="([0-9a-f]{64})"/', (string) $html, $matches);

    return $matches[1];
}

test('support access is disabled by default', function () {
    config(['impersonation.support.enabled' => false]);

    $this->actingAs($this->admin, 'platform')->get(route('platform.tenants.show', $this->tenant))
        ->assertOk()
        ->assertDontSee('Start support session');
    $this->actingAs($this->admin, 'platform')->get(route('platform.support-sessions.create', $this->tenant))->assertForbidden();
    $this->actingAs($this->admin, 'platform')->get(route('platform.support-sessions.index'))->assertOk()->assertSee('Support access is disabled');

    expect(fn () => startSupportSession($this->admin, $this->tenant))->toThrow(AuthorizationException::class);

    postHandoff($this->tenant, str_repeat('a', 64))->assertNotFound();
});

test('guests and tenant users cannot start support access', function () {
    $this->get(route('platform.support-sessions.create', $this->tenant))->assertRedirect(route('platform.login'));
    $this->post(route('platform.support-sessions.store', $this->tenant), ['target_user_id' => $this->tenantAdminId])->assertRedirect(route('platform.login'));

    $allowed = $this->tenant->run(fn () => Gate::forUser(User::query()->findOrFail($this->tenantAdminId))->allows('start-support-session'));
    expect($allowed)->toBeFalse()
        ->and(SupportSession::query()->count())->toBe(0);
});

test('the start page shows the company and only its active tenant admins', function () {
    secondTenantAdmin($this->tenant);
    secondTenantAdmin($this->tenant, 'former@acme.test', active: false);
    $this->tenant->run(fn () => User::factory()->create(['email' => 'clerk@acme.test']));

    $this->actingAs($this->admin, 'platform')->get(route('platform.tenants.show', $this->tenant))->assertSee('Start support session');

    $this->actingAs($this->admin, 'platform')->get(route('platform.support-sessions.create', $this->tenant))
        ->assertOk()
        ->assertSee('Acme Freight')
        ->assertSee('admin@acme.test')
        ->assertSee('second@acme.test')
        ->assertDontSee('former@acme.test')
        ->assertDontSee('clerk@acme.test');
});

test('starting requires the platform admin to re-enter their password', function () {
    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.support-sessions.store', $this->tenant), [
            'target_user_id' => $this->tenantAdminId,
            'reason' => 'Ticket 4711: dashboard does not load',
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors('password');

    expect(SupportSession::query()->count())->toBe(0);
});

test('repeated wrong passwords are rate limited', function () {
    // The message counts down in seconds, so a second passing mid-test must not change it.
    $this->freezeTime();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($this->admin, 'platform')->post(route('platform.support-sessions.store', $this->tenant), [
            'target_user_id' => $this->tenantAdminId, 'reason' => 'Ticket 4711: dashboard does not load', 'password' => 'wrong',
        ]);
    }

    $this->actingAs($this->admin, 'platform')->post(route('platform.support-sessions.store', $this->tenant), [
        'target_user_id' => $this->tenantAdminId, 'reason' => 'Ticket 4711: dashboard does not load', 'password' => 'password',
    ])->assertSessionHasErrors(['password' => 'Too many attempts. Please try again in 300 seconds.']);

    expect(SupportSession::query()->count())->toBe(0);
});

test('a reason is required', function (string $reason) {
    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.support-sessions.store', $this->tenant), ['target_user_id' => $this->tenantAdminId, 'reason' => $reason, 'password' => 'password'])
        ->assertSessionHasErrors('reason');

    expect(SupportSession::query()->count())->toBe(0);
})->with([
    'missing' => [''],
    'too short' => ['help'],
    'too long' => [str_repeat('x', 1001)],
]);

test('a fresh authenticator code is required when two-factor is enforced', function () {
    enableSupportAccess(requireTwoFactor: true);

    $this->actingAs($this->admin, 'platform')->get(route('platform.support-sessions.create', $this->tenant))
        ->assertSee('Two-factor authentication required');
    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.support-sessions.store', $this->tenant), ['target_user_id' => $this->tenantAdminId, 'reason' => 'Ticket 4711: dashboard does not load', 'password' => 'password', 'code' => '123456'])
        ->assertSessionHasErrors(['code' => 'Set up two-factor authentication for your account before starting support sessions.']);

    $secret = enableTwoFactorFor($this->admin);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    startSupportOverHttp($this->admin, $this->tenant, $this->tenantAdminId, ['code' => $code]);

    // The same code cannot be replayed for a second session.
    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.support-sessions.store', $this->tenant), ['target_user_id' => $this->tenantAdminId, 'reason' => 'Ticket 4711: dashboard does not load', 'password' => 'password', 'code' => $code])
        ->assertSessionHasErrors('code');

    expect(SupportSession::query()->count())->toBe(1);
});

test('the start response hands over a single-use credential by POST, never in a URL', function () {
    $response = $this->actingAs($this->admin, 'platform')
        ->post(route('platform.support-sessions.store', $this->tenant), [
            'target_user_id' => $this->tenantAdminId,
            'reason' => 'Ticket 4711: dashboard does not load',
            'password' => 'password',
        ])
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('action="'.$this->tenant->url('support/handoff').'"', false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    preg_match('/name="token" value="([0-9a-f]{64})"/', (string) $response->getContent(), $matches);
    $session = SupportSession::query()->sole();

    expect($matches[1])->toHaveLength(64)
        ->and($session->handoff_token_hash)->toBe(hash('sha256', $matches[1]))
        ->and($session->status())->toBe(ImpersonationStatus::Pending)
        ->and($session->tenant_id)->toBe($this->tenant->id)
        ->and($session->target_user_id)->toBe($this->tenantAdminId)
        ->and($session->platform_admin_id)->toBe($this->admin->id)
        ->and($session->expires_at->diffInMinutes($session->started_at, true))->toEqual(15.0)
        ->and($session->handoff_expires_at->diffInSeconds($session->started_at, true))->toEqual(60.0);
});

test('starting is audited and every active tenant admin is notified', function () {
    secondTenantAdmin($this->tenant);
    secondTenantAdmin($this->tenant, 'former@acme.test', active: false);

    [$session] = startSupportSession($this->admin, $this->tenant, reason: 'Ticket 4711: dashboard does not load');

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::SupportSessionStarted)->sole();
    expect($entry->platform_admin_id)->toBe($this->admin->id)
        ->and($entry->actor_type)->toBe(PlatformAuditLog::ActorPlatformAdmin)
        ->and($entry->tenant_id)->toBe($this->tenant->id)
        ->and($entry->reason)->toBe('Ticket 4711: dashboard does not load')
        ->and($entry->new_values)->toMatchArray(['support_session_id' => $session->id, 'target' => 'admin@acme.test']);

    $notified = $this->tenant->run(fn () => DB::table('notifications')->where('type', SupportAccessStarted::class)->get());
    expect($notified)->toHaveCount(2);

    $data = json_decode($notified->first()->data, true);
    expect($data)->toMatchArray([
        'support_session_id' => $session->id,
        'actor_email' => 'ops@platform.test',
        'reason' => 'Ticket 4711: dashboard does not load',
    ]);
});

test('the handoff opens a restricted session as the selected tenant admin', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);

    postHandoff($this->tenant, $token)->assertRedirect($this->tenant->url('dashboard'));

    $this->get($this->tenant->url('dashboard'))
        ->assertOk()
        ->assertSee('Impersonation active')
        ->assertSee('Platform support')
        ->assertSee('ops@platform.test')
        ->assertSee('admin@acme.test')
        ->assertSee('End impersonation');

    $session->refresh();
    expect(auth('web')->id())->toBe($this->tenantAdminId)
        ->and($session->status())->toBe(ImpersonationStatus::Active)
        ->and($session->handoff_token_hash)->toBeNull()
        ->and($session->handoff_consumed_at)->not->toBeNull();

    // The target account itself is not changed by the support login.
    $this->tenant->run(fn () => expect(User::query()->findOrFail($this->tenantAdminId)->remember_token)->toBeNull());
});

test('a handoff credential cannot be replayed', function () {
    [, $token] = startSupportSession($this->admin, $this->tenant);

    postHandoff($this->tenant, $token)->assertRedirect();

    // A second browser (no session) replays the same credential.
    $this->flushSession();
    auth()->forgetGuards();

    postHandoff($this->tenant, $token)->assertForbidden()->assertSee('invalid, has already been used or has expired');
    expect(auth('web')->check())->toBeFalse();
});

test('expired handoff credentials are rejected', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);

    $this->travel(61)->seconds();

    postHandoff($this->tenant, $token)->assertForbidden();

    expect(auth('web')->check())->toBeFalse()
        ->and($session->refresh()->status())->toBe(ImpersonationStatus::Expired);
});

test('invalid credentials are rejected', function (string $token) {
    startSupportSession($this->admin, $this->tenant);

    postHandoff($this->tenant, $token)->assertForbidden();
    expect(auth('web')->check())->toBeFalse();
})->with([
    'empty' => [''],
    'wrong' => [str_repeat('0', 64)],
    'too long' => [str_repeat('a', 65)],
]);

test('a credential issued for tenant A cannot authenticate into tenant B, and is burned', function () {
    $globex = provisionTenant('globex')->tenant;
    [$session, $token] = startSupportSession($this->admin, $this->tenant);

    postHandoff($globex, $token)->assertForbidden();
    expect(auth('web')->check())->toBeFalse();

    postHandoff($this->tenant, $token)->assertForbidden();

    $session->refresh();
    expect($session->end_reason)->toBe(ImpersonationEndReason::SessionMismatch)
        ->and($session->handoff_token_hash)->toBeNull();
});

test('support access requires a separate session per tenant', function () {
    $globex = provisionTenant('globex')->tenant;
    [, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    // The acme support session gives nothing on globex: separate subdomain, separate session.
    auth()->forgetGuards();
    $this->get($globex->url('dashboard'))->assertRedirect($globex->url('login'));
});

test('a suspended tenant cannot be accessed, and suspension ends its support sessions', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);

    app(SuspendTenant::class)->handle($this->admin, $this->tenant, 'Contract under review');

    expect($session->refresh()->end_reason)->toBe(ImpersonationEndReason::TenantSuspended)
        ->and(PlatformAuditLog::query()->where('action', PlatformAuditAction::SupportSessionEnded)->sole()->actor_type)->toBe(PlatformAuditLog::ActorSystem);

    postHandoff($this->tenant, $token)->assertForbidden();

    $this->actingAs($this->admin, 'platform')->get(route('platform.support-sessions.create', $this->tenant))
        ->assertSee('Support access cannot bypass a suspension');
    expect(fn () => startSupportSession($this->admin, $this->tenant))->toThrow(ImpersonationRefused::class);
});

test('a suspension during an active session ends it even after reactivation', function () {
    [, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    // Suspended and reactivated by direct updates, without the action that ends sessions.
    Tenant::query()->whereKey($this->tenant->id)->update(['suspended_at' => now()]);
    PlatformAuditLog::record($this->admin, $this->tenant, PlatformAuditAction::TenantSuspended, reason: 'Test');
    Tenant::query()->whereKey($this->tenant->id)->update(['suspended_at' => null]);

    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('login'));
    expect(SupportSession::query()->sole()->end_reason)->toBe(ImpersonationEndReason::TenantSuspended);
});

test('companies that are not ready cannot be accessed', function (TenantStatus $status) {
    $pending = centralTenant('pending', $status);
    subscribeTenant($pending);

    $this->actingAs($this->admin, 'platform')->get(route('platform.tenants.show', $pending))->assertDontSee('Start support session');
    $this->actingAs($this->admin, 'platform')->get(route('platform.support-sessions.create', $pending))->assertSee('has not finished provisioning');

    expect(fn () => startSupportSession($this->admin, $pending, 1))->toThrow(ImpersonationRefused::class);
})->with([TenantStatus::Pending, TenantStatus::Provisioning, TenantStatus::Failed]);

test('subscription restrictions cannot be bypassed', function () {
    $unsubscribed = provisionTenant('globex', subscribed: false)->tenant;

    $this->actingAs($this->admin, 'platform')->get(route('platform.support-sessions.create', $unsubscribed))
        ->assertSee('Support access cannot bypass subscription restrictions');
    expect(fn () => startSupportSession($this->admin, $unsubscribed))->toThrow(ImpersonationRefused::class);
});

test('an active session ends when the subscription stops being active', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);
    $this->get($this->tenant->url('dashboard'))->assertOk();

    SubscriptionTerm::query()->where('tenant_id', $this->tenant->id)->update(['ends_on' => today()]);

    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('login'));
    expect($session->refresh()->end_reason)->toBe(ImpersonationEndReason::SubscriptionInactive)
        ->and(auth('web')->check())->toBeFalse();
});

test('inactive or non-admin accounts cannot be targeted', function () {
    $former = secondTenantAdmin($this->tenant, 'former@acme.test', active: false);
    $clerk = $this->tenant->run(fn () => User::factory()->create(['email' => 'clerk@acme.test']));

    expect(fn () => startSupportSession($this->admin, $this->tenant, $former->id))->toThrow(ImpersonationRefused::class, 'not an active Tenant Admin')
        ->and(fn () => startSupportSession($this->admin, $this->tenant, $clerk->id))->toThrow(ImpersonationRefused::class)
        ->and(fn () => startSupportSession($this->admin, $this->tenant, 999))->toThrow(ImpersonationRefused::class)
        ->and(SupportSession::query()->count())->toBe(0);
});

test('a target deactivated before the handoff or during the session loses the session', function () {
    $second = secondTenantAdmin($this->tenant);
    [$before, $tokenBefore] = startSupportSession($this->admin, $this->tenant, $second->id);
    [$during, $tokenDuring] = startSupportSession($this->admin, $this->tenant);

    $this->tenant->run(function () use ($second) {
        $second->forceFill(['is_active' => false])->save();
    });
    postHandoff($this->tenant, $tokenBefore)->assertForbidden();
    expect($before->refresh()->end_reason)->toBe(ImpersonationEndReason::TargetDeactivated);

    $this->flushSession();
    auth()->forgetGuards();
    postHandoff($this->tenant, $tokenDuring)->assertRedirect();

    // Another Tenant Admin deactivates the supported admin.
    $this->tenant->run(function () use ($second) {
        $second->forceFill(['is_active' => true])->save();
        app(SetEmployeeActivation::class)->handle($second, User::query()->findOrFail($this->tenantAdminId), false);
    });

    expect($during->refresh()->end_reason)->toBe(ImpersonationEndReason::TargetDeactivated);
    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('login'));
});

test('the session ends automatically when its time limit is reached', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    $this->travel(14)->minutes();
    $this->get($this->tenant->url('dashboard'))->assertOk();

    $this->travel(2)->minutes();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('login'));

    $session->refresh();
    expect($session->status())->toBe(ImpersonationStatus::Expired)
        ->and($session->end_reason)->toBe(ImpersonationEndReason::Expired)
        ->and(auth('web')->check())->toBeFalse();

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::SupportSessionEnded)->sole();
    expect($entry->actor_type)->toBe(PlatformAuditLog::ActorSystem)
        ->and($entry->new_values['end_reason'])->toBe('expired');
});

test('ending the session logs the tenant browser out and returns to the platform', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    $this->post($this->tenant->url('impersonation/end'))
        ->assertRedirect(route('platform.support-sessions.show', $session));

    expect(auth('web')->check())->toBeFalse()
        ->and($session->refresh()->end_reason)->toBe(ImpersonationEndReason::EndedByActor);

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::SupportSessionEnded)->sole();
    expect($entry->platform_admin_id)->toBe($this->admin->id)
        ->and($entry->actor_type)->toBe(PlatformAuditLog::ActorPlatformAdmin);

    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('login'));
});

test('logging out ends the support session', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    $this->post($this->tenant->url('logout'))->assertRedirect();

    expect($session->refresh()->end_reason)->toBe(ImpersonationEndReason::LoggedOut)
        ->and(auth('web')->check())->toBeFalse();
});

test('ending support access keeps the target\'s remember-me token', function (string $path) {
    $this->tenant->run(fn () => User::query()->findOrFail($this->tenantAdminId)->forceFill(['remember_token' => 'keep-me'])->save());
    [$session, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    $this->post($this->tenant->url($path))->assertRedirect();

    expect($session->refresh()->status())->toBe(ImpersonationStatus::Ended)
        ->and(auth('web')->check())->toBeFalse();
    $this->tenant->run(fn () => expect(User::query()->findOrFail($this->tenantAdminId)->remember_token)->toBe('keep-me'));
})->with(['impersonation/end', 'logout']);

test('a platform admin can revoke a session, which loses access on its next request', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    $other = PlatformAdmin::factory()->create(['email' => 'lead@platform.test']);
    $this->actingAs($other, 'platform')->post(route('platform.support-sessions.revoke', $session))->assertRedirect();

    expect($session->refresh()->status())->toBe(ImpersonationStatus::Revoked)
        ->and($session->ended_by_email)->toBe('lead@platform.test');

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::SupportSessionRevoked)->sole();
    expect($entry->platform_admin_id)->toBe($other->id);

    auth()->shouldUse('web');
    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('login'));
    expect(auth('web')->check())->toBeFalse();

    // Revoking twice changes nothing.
    $this->actingAs($other, 'platform')->post(route('platform.support-sessions.revoke', $session))->assertSessionHas('error');
    expect(PlatformAuditLog::query()->where('action', PlatformAuditAction::SupportSessionRevoked)->count())->toBe(1);
});

test('a tenant admin can see and revoke support access to their company', function () {
    $second = secondTenantAdmin($this->tenant);
    [$session] = startSupportSession($this->admin, $this->tenant);

    tenancy()->initialize($this->tenant);

    Livewire::actingAs($second)
        ->test('pages::access-history.index')
        ->assertSee('ops@platform.test')
        ->assertSee('Ticket 4711: dashboard does not load')
        ->assertSee('Platform support accessed your company once')
        ->call('markNoticesRead')
        ->assertDontSee('Platform support accessed your company once')
        ->call('revokeSupport', $session->id);

    expect($session->refresh()->status())->toBe(ImpersonationStatus::Revoked)
        ->and(PlatformAuditLog::query()->where('action', PlatformAuditAction::SupportSessionRevoked)->sole()->actor_type)->toBe(PlatformAuditLog::ActorTenantUser);
});

test('support history and notices are scoped to their own company', function () {
    $globex = provisionTenant('globex')->tenant;
    [$acmeSession] = startSupportSession($this->admin, $this->tenant, reason: 'Acme ticket about invoices');
    startSupportSession($this->admin, $globex, reason: 'Globex ticket about roles');

    tenancy()->initialize($globex);
    $globexAdmin = tenantAdminUser();

    Livewire::actingAs($globexAdmin)
        ->test('pages::access-history.index')
        ->assertSee('Globex ticket about roles')
        ->assertDontSee('Acme ticket about invoices')
        ->call('revokeSupport', $acmeSession->id);

    expect($acmeSession->refresh()->status())->toBe(ImpersonationStatus::Pending)
        ->and(DB::table('notifications')->count())->toBe(1);

    // Employees (non Tenant Admins) cannot see the access history.
    $clerk = User::factory()->create();
    $this->actingAs($clerk, 'web')->get($globex->url('access-history'))->assertForbidden();
});

test('no credential or token is ever written to the audit trail or notifications', function () {
    [$session, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);
    $this->get($this->tenant->url('dashboard'));
    $this->post($this->tenant->url('employees'));
    $this->post($this->tenant->url('impersonation/end'));

    tenancy()->end();
    $central = json_encode([
        PlatformAuditLog::query()->get()->toArray(),
        DB::table('support_sessions')->get(),
        DB::table('support_session_events')->get(),
    ]);
    $tenantData = $this->tenant->run(fn () => json_encode(DB::table('notifications')->get()));

    expect($central)->not->toContain($token)
        ->and($central)->not->toContain(hash('sha256', $token))
        ->and($tenantData)->not->toContain($token)
        ->and($central)->not->toContain('password');
});

test('the support session cannot reach the central platform', function () {
    [, $token] = startSupportSession($this->admin, $this->tenant);
    postHandoff($this->tenant, $token);

    // Central routes exist only on the central domain.
    $this->get($this->tenant->url('admin'))->assertNotFound();
    $this->get($this->tenant->url('admin/support-sessions'))->assertNotFound();
});
