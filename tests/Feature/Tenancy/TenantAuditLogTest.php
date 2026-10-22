<?php

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Impersonation\ActiveImpersonation;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\TenantAuditLog;
use App\Models\User;
use App\Policies\TenantAuditLogPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Record a probe action in its own transaction, as an audited action would.
 *
 * @param  array<string, mixed>  $attributes
 */
function recordAudit(?User $actor, array $attributes = []): TenantAuditLog
{
    return DB::transaction(fn () => TenantAuditLog::record(
        AuditProbeAction::Changed,
        $actor,
        $attributes['subject'] ?? null,
        $attributes['old'] ?? null,
        $attributes['new'] ?? null,
        $attributes['reason'] ?? null,
        $attributes['metadata'] ?? null,
    ));
}

test('a record holds the action, actor, subject, values and reason', function () {
    actingInTenant();
    $admin = tenantAdminUser();
    $employee = User::factory()->create(['name' => 'Nadia', 'email' => 'nadia@acme.test']);

    $record = recordAudit($admin, [
        'subject' => $employee,
        'old' => ['name' => 'Nadia'],
        'new' => ['name' => 'Nadia Samir'],
        'reason' => 'Name corrected from the passport',
        'metadata' => ['source' => 'pre-alert'],
    ])->fresh();

    expect($record)
        ->action->toBe('probe.changed')
        ->subject_type->toBe($employee->getMorphClass())
        ->subject_id->toBe($employee->id)
        ->actor_type->toBe(TenantAuditLog::ActorTenantUser)
        ->actor_user_id->toBe($admin->id)
        ->actor_email->toBe($admin->email)
        ->actor_name->toBe($admin->name)
        ->impersonation_kind->toBeNull()
        ->original_actor_id->toBeNull()
        ->old_values->toBe(['name' => 'Nadia'])
        ->new_values->toBe(['name' => 'Nadia Samir'])
        ->metadata->toBe(['source' => 'pre-alert'])
        ->reason->toBe('Name corrected from the passport')
        ->created_at->not->toBeNull()
        ->and($record->wasImpersonated())->toBeFalse();
});

test('an action of the application itself is recorded as the system', function () {
    actingInTenant();

    expect(recordAudit(null)->fresh())
        ->actor_type->toBe(TenantAuditLog::ActorSystem)
        ->actor_user_id->toBeNull()
        ->actor_email->toBe('system')
        ->and(recordAudit(null)->isSystemAction())->toBeTrue();
});

test('secrets are never stored, at any depth', function () {
    actingInTenant();

    $record = recordAudit(tenantAdminUser(), [
        'old' => ['password' => 'old-secret', 'name' => 'A'],
        'new' => ['Password' => 'new-secret', 'two_factor_secret' => 'x', 'carrier' => ['api_key' => 'k', 'apiKey' => 'k2', 'access_token' => 't', 'login' => 'ops']],
        'metadata' => ['remember_token' => 'r', 'session_id' => 's', 'cookie' => 'c', 'impersonation_session_id' => 7],
    ])->fresh();

    // Compared without key order: MySQL normalizes the order of JSON object keys.
    expect($record->old_values)->toEqual(['password' => '[redacted]', 'name' => 'A'])
        ->and($record->new_values)->toEqual(['Password' => '[redacted]', 'two_factor_secret' => '[redacted]', 'carrier' => ['api_key' => '[redacted]', 'apiKey' => '[redacted]', 'access_token' => '[redacted]', 'login' => 'ops']])
        ->and($record->metadata)->toEqual(['remember_token' => '[redacted]', 'session_id' => '[redacted]', 'cookie' => '[redacted]', 'impersonation_session_id' => 7]);
});

test('a record must be written in the transaction of the change it describes', function () {
    actingInTenant();
    $admin = tenantAdminUser();

    expect(fn () => TenantAuditLog::record(AuditProbeAction::Changed, $admin))
        ->toThrow(LogicException::class, 'inside the transaction')
        ->and(TenantAuditLog::query()->count())->toBe(0);
});

test('a change that is rolled back takes its record with it', function () {
    actingInTenant();
    $admin = tenantAdminUser();

    expect(fn () => DB::transaction(function () use ($admin): void {
        $admin->forceFill(['name' => 'Changed'])->save();
        TenantAuditLog::record(AuditProbeAction::Changed, $admin, $admin, ['name' => 'Admin'], ['name' => 'Changed']);

        throw new RuntimeException('The change failed after it was recorded.');
    }))->toThrow(RuntimeException::class);

    expect(TenantAuditLog::query()->count())->toBe(0)
        ->and($admin->fresh()->name)->not->toBe('Changed');
});

test('records can only be written in tenant context, about tenant records, with string actions', function () {
    actingInTenant();
    $platformAdmin = PlatformAdmin::factory()->create();

    expect(fn () => DB::transaction(fn () => TenantAuditLog::record(AuditProbeAction::Changed, null, $platformAdmin)))
        ->toThrow(LogicException::class, 'only describe tenant records')
        ->and(fn () => DB::transaction(fn () => TenantAuditLog::record(IntegerAuditProbeAction::Changed, null)))
        ->toThrow(LogicException::class, 'string-backed');

    tenancy()->end();

    expect(fn () => DB::transaction(fn () => TenantAuditLog::record(AuditProbeAction::Changed, null)))
        ->toThrow(LogicException::class, 'tenant context');
});

test('records cannot be changed or deleted through the application', function (Closure $operation) {
    actingInTenant();
    $record = recordAudit(tenantAdminUser(), ['reason' => 'Original']);

    expect(fn () => $operation($record))->toThrow(LogicException::class)
        ->and(TenantAuditLog::query()->sole()->only(['id', 'reason', 'action']))->toBe(['id' => $record->id, 'reason' => 'Original', 'action' => 'probe.changed']);
})->with([
    'update the record' => [fn (TenantAuditLog $record) => $record->update(['reason' => 'Rewritten'])],
    'save a change' => [fn (TenantAuditLog $record) => $record->forceFill(['reason' => 'Rewritten'])->save()],
    'delete the record' => [fn (TenantAuditLog $record) => $record->delete()],
    'mass update' => [fn () => TenantAuditLog::query()->update(['reason' => 'Rewritten'])],
    'mass delete' => [fn () => TenantAuditLog::query()->whereKeyNot(0)->delete()],
    'force delete' => [fn () => TenantAuditLog::query()->forceDelete()],
    'increment' => [fn () => TenantAuditLog::query()->increment('subject_id')],
    'decrement' => [fn () => TenantAuditLog::query()->decrement('subject_id')],
    'touch' => [fn () => TenantAuditLog::query()->touch('created_at')],
    'upsert' => [fn (TenantAuditLog $record) => TenantAuditLog::query()->upsert([['id' => $record->id, 'reason' => 'Rewritten']], ['id'], ['reason'])],
    'destroy' => [fn (TenantAuditLog $record) => TenantAuditLog::destroy($record->id)],
]);

test('during a support session the Platform Admin is recorded as the original actor', function () {
    enableSupportAccess();
    $platformAdmin = PlatformAdmin::factory()->create(['name' => 'Ops Agent', 'email' => 'ops@platform.test']);
    $tenant = provisionTenant('acme')->tenant;
    [$session] = startSupportSession($platformAdmin, $tenant);

    tenancy()->initialize($tenant);
    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));
    $admin = User::query()->findOrFail($session->target_user_id);

    expect(recordAudit($admin)->fresh())
        ->actor_user_id->toBe($admin->id)
        ->impersonation_kind->toBe('support')
        ->impersonation_session_id->toBe($session->id)
        ->original_actor_type->toBe(TenantAuditLog::ActorPlatformAdmin)
        ->original_actor_id->toBe($platformAdmin->id)
        ->original_actor_email->toBe('ops@platform.test')
        ->original_actor_name->toBe('Ops Agent');

    // Another user's action in the same request is not attributed to the support session.
    expect(recordAudit(User::factory()->create())->fresh()->impersonation_kind)->toBeNull();
});

test('during an employee view the Tenant Admin is recorded as the original actor', function () {
    actingInTenant();
    $admin = tenantAdminUser();
    $employee = User::factory()->create(['email' => 'clerk@acme.test'])->assignRole(TenantRole::Employee->value);
    $session = ImpersonationSession::query()->create([
        'impersonator_user_id' => $admin->id,
        'impersonator_name' => $admin->name,
        'impersonator_email' => $admin->email,
        'target_user_id' => $employee->id,
        'target_name' => $employee->name,
        'target_email' => $employee->email,
        'reason' => 'Reproduce a ticket',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(15),
    ]);
    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));

    expect(recordAudit($employee)->fresh())
        ->actor_user_id->toBe($employee->id)
        ->impersonation_kind->toBe('employee')
        ->impersonation_session_id->toBe($session->id)
        ->original_actor_type->toBe(TenantAuditLog::ActorTenantUser)
        ->original_actor_id->toBe($admin->id)
        ->original_actor_email->toBe($admin->email);
});

test('records written during one HTTP request share a correlation id, other requests get another', function () {
    $tenant = provisionTenant('acme')->tenant;
    Route::middleware(['web', 'tenant'])->get('audit-probe', fn () => DB::transaction(fn () => [
        TenantAuditLog::record(AuditProbeAction::Changed, null)->correlation_id,
        TenantAuditLog::record(AuditProbeAction::Changed, null)->correlation_id,
    ]));

    [$first, $second] = $this->get($tenant->url('audit-probe'))->assertOk()->json();
    [$third] = $this->get($tenant->url('audit-probe'))->assertOk()->json();

    expect($first)->toBeString()->toBe($second)
        ->and($third)->toBeString()->not->toBe($first);

    // Outside an HTTP request (console, queue: no route) nothing is linked.
    app()->instance('request', Request::create('/'));
    tenancy()->initialize($tenant);
    expect(recordAudit(null)->correlation_id)->toBeNull();
});

test('records live only in the company database', function () {
    $centralConnection = config('tenancy.database.central_connection');
    $acme = provisionTenant('acme')->tenant;
    $platformRecords = PlatformAuditLog::query()->count();

    $acme->run(fn () => recordAudit(null));

    expect(Schema::connection($centralConnection)->hasTable('tenant_audit_logs'))->toBeFalse()
        ->and(PlatformAuditLog::query()->count())->toBe($platformRecords)
        ->and($acme->run(fn () => Schema::hasTable('tenant_audit_logs')))->toBeTrue()
        ->and(collect(Schema::connection($centralConnection)->getTableListing())->map(fn (string $table): string => Str::afterLast($table, '.')))
        ->not->toContain('tenant_audit_logs');
});

test('one company never sees the records of another, even with the same ids', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;

    $acmeRecord = $acme->run(fn () => recordAudit(tenantAdminUser(), ['reason' => 'acme only']));
    $globexRecord = $globex->run(fn () => recordAudit(tenantAdminUser(), ['reason' => 'globex only']));

    expect($acmeRecord->id)->toBe($globexRecord->id)
        ->and($acme->run(fn () => TenantAuditLog::query()->pluck('reason')->all()))->toBe(['acme only'])
        ->and($globex->run(fn () => TenantAuditLog::query()->pluck('reason')->all()))->toBe(['globex only'])
        ->and($globex->run(fn () => TenantAuditLog::query()->find($acmeRecord->id)?->actor_email))->toBe('admin@globex.test');
});

test('reading the audit log requires the audit permission', function () {
    actingInTenant();
    $record = recordAudit(null);
    $admin = tenantAdminUser();
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $auditor = userWithPermissions([TenantPermission::AuditView]);
    $fclUser = userWithPermissions([TenantPermission::FclJobsView, TenantPermission::FclJobsCreate]);
    $deactivated = userWithPermissions([TenantPermission::AuditView], ['is_active' => false]);

    expect(Gate::forUser($admin)->allows('viewAny', TenantAuditLog::class))->toBeTrue()
        ->and(Gate::forUser($auditor)->allows('view', $record))->toBeTrue()
        ->and(Gate::forUser($employee)->allows('viewAny', TenantAuditLog::class))->toBeFalse()
        ->and(Gate::forUser($fclUser)->allows('viewAny', TenantAuditLog::class))->toBeFalse()
        ->and(Gate::forUser($deactivated)->allows('viewAny', TenantAuditLog::class))->toBeFalse()
        ->and(Gate::forUser(PlatformAdmin::factory()->create())->allows('viewAny', TenantAuditLog::class))->toBeFalse()
        // Nobody may change records through authorization either.
        ->and(Gate::forUser($admin)->allows('update', $record))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $record))->toBeFalse();
});

test('the audit policy itself refuses an impersonated user', function () {
    actingInTenant();
    $admin = tenantAdminUser();
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $session = ImpersonationSession::query()->create([
        'impersonator_user_id' => $admin->id,
        'impersonator_name' => $admin->name,
        'impersonator_email' => $admin->email,
        'target_user_id' => $employee->id,
        'target_name' => $employee->name,
        'target_email' => $employee->email,
        'reason' => 'Reproduce a ticket',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(15),
    ]);
    $employee->givePermissionTo(TenantPermission::AuditView->value);
    $policy = new TenantAuditLogPolicy;

    expect($policy->viewAny($employee))->toBeTrue();

    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));

    expect($policy->viewAny($employee))->toBeFalse()
        ->and($policy->viewAny($admin))->toBeTrue();
});

enum AuditProbeAction: string
{
    case Changed = 'probe.changed';
}

enum IntegerAuditProbeAction: int
{
    case Changed = 1;
}
