<?php

use App\Enums\TenantPermission;
use App\Models\BillingPayment;
use App\Models\ImpersonationEvent;
use App\Models\PlatformAuditLog;
use App\Models\SupportSessionEvent;
use App\Models\TenantAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Milestone 5.2: append-only records cannot be changed, emptied or forged through the
// application, and audit values never keep secrets.

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
});

function writeAuditRecord(array $newValues = [], array $metadata = []): TenantAuditLog
{
    return DB::transaction(fn () => TenantAuditLog::record(TenantPermission::AuditView, test()->admin, null, null, $newValues, null, $metadata));
}

test('the tenant audit cannot be emptied or updated in place through Eloquent', function (Closure $operation) {
    writeAuditRecord(['name' => 'A']);

    expect($operation)->toThrow(LogicException::class);
    expect(TenantAuditLog::query()->count())->toBe(1)
        ->and(TenantAuditLog::query()->sole()->new_values)->toBe(['name' => 'A']);
})->with([
    'truncate' => fn () => TenantAuditLog::truncate(),
    'updateOrInsert' => fn () => TenantAuditLog::query()->updateOrInsert(['id' => TenantAuditLog::query()->value('id')], ['reason' => 'rewritten']),
]);

test('audit records cannot be forged around record()', function (Closure $operation) {
    $original = writeAuditRecord(['name' => 'A']);

    expect(fn () => $operation($original))->toThrow(LogicException::class, 'only be written through TenantAuditLog::record()');
    expect(TenantAuditLog::query()->count())->toBe(1);
})->with([
    'create' => fn () => TenantAuditLog::query()->create(['action' => 'x', 'actor_type' => 'tenant_user', 'actor_name' => 'Someone else', 'actor_email' => 'else@acme.test']),
    'forceCreate' => fn () => TenantAuditLog::query()->forceCreate(['action' => 'x', 'actor_type' => 'system', 'actor_name' => 'System', 'actor_email' => 'system']),
    'replicate' => fn (TenantAuditLog $original) => $original->replicate()->fill(['actor_name' => 'Someone else'])->save(),
]);

test('central append-only records refuse mass updates and deletes', function (Closure $operation) {
    tenancy()->end();

    expect($operation)->toThrow(LogicException::class);
})->with([
    'platform audit update' => fn () => PlatformAuditLog::query()->update(['reason' => 'rewritten']),
    'platform audit delete' => fn () => PlatformAuditLog::query()->delete(),
    'platform audit truncate' => fn () => PlatformAuditLog::truncate(),
    'payments update' => fn () => BillingPayment::query()->update(['amount' => 1]),
    'support events delete' => fn () => SupportSessionEvent::query()->delete(),
]);

test('tenant impersonation events refuse mass deletes', function () {
    expect(fn () => ImpersonationEvent::query()->delete())->toThrow(LogicException::class);
});

test('more secret-looking keys are redacted, at any depth', function () {
    $record = writeAuditRecord([
        'two_factor_recovery_codes' => ['a', 'b'],
        'private_key' => 'k',
        'privateKey' => 'k',
        'authorization' => 'Bearer x',
        'bearer' => 'x',
        'otp' => '123456',
        'pin' => '1234',
        'card_pin' => '1234',
        'passcode' => 'x',
        'pwd' => 'x',
        'sessionId' => 'abc',
        'iban' => 'EG00',
        'card_number' => '4111',
    ]);

    expect(collect($record->new_values)->unique()->values()->all())->toBe([TenantAuditLog::Redacted]);
});

test('ordinary keys that contain those letters are kept', function () {
    $values = ['shipping_line' => 'MSC', 'pinned' => true, 'passenger_count' => 2, 'description' => 'd', 'impersonation_session_id' => 7, 'compass' => 'N'];

    expect(writeAuditRecord($values)->new_values)->toBe($values);
});

test('collections and serializable objects are redacted like arrays', function () {
    $dto = new class implements JsonSerializable
    {
        /** @return array<string, string> */
        public function jsonSerialize(): array
        {
            return ['carrier' => 'MSC', 'api_key' => 'live-key'];
        }
    };

    $record = writeAuditRecord(metadata: ['config' => collect(['endpoint' => 'https://x', 'token' => 'live-token']), 'dto' => $dto]);

    expect($record->metadata)->toBe([
        'config' => ['endpoint' => 'https://x', 'token' => TenantAuditLog::Redacted],
        'dto' => ['carrier' => 'MSC', 'api_key' => TenantAuditLog::Redacted],
    ]);
});

test('the subject type holds long class names', function () {
    $column = collect(Schema::getColumns('tenant_audit_logs'))->firstWhere('name', 'subject_type');

    expect($column['type'])->toBe('varchar(255)');
});
