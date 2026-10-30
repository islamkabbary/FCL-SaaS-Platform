<?php

use App\Actions\Fcl\CreateParty;
use App\Actions\Fcl\UpdateParty;
use App\Enums\Fcl\FclAuditAction;
use App\Enums\TenantPermission;
use App\Exceptions\Fcl\PossibleDuplicateParty;
use App\Impersonation\ActiveImpersonation;
use App\Impersonation\RestrictionPolicy;
use App\Models\Fcl\Party;
use App\Models\ImpersonationEvent;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Models\SupportSessionEvent;
use App\Models\Tenant;
use App\Models\TenantAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/*
 * FCL parties (M1-A6): tenant-wide master data with audited create and update, and a
 * warning, not a rule, for likely duplicates (owner decisions of 2026-10-23).
 */

/**
 * A user of the tenant with the given party permissions, logged in over HTTP.
 *
 * @param  list<TenantPermission>  $permissions
 */
function loginWithPartyPermissions(Tenant $tenant, array $permissions): User
{
    $user = $tenant->run(fn (): User => userWithPermissions($permissions));
    loginToTenant($tenant, $user);

    return $user;
}

/**
 * Fill the party form of the page and press Save.
 *
 * @param  array<string, mixed>  $form
 */
function submitPartyForm(Tenant $tenant, array $form): TestResponse
{
    return livewireRequest($tenant, 'fcl/parties', [['method' => 'save', 'params' => []]], $form);
}

/**
 * The page's Livewire snapshot, as the browser holds it after loading the page.
 */
function partiesPageSnapshot(Tenant $tenant): string
{
    $html = (string) test()->get($tenant->url('fcl/parties'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);

    return html_entity_decode($match[1], ENT_QUOTES);
}

/**
 * Send a Livewire update of the parties page from a snapshot, or from the state in which
 * a previous update left the page, as the browser does for its next action.
 *
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 * @param  array<string, mixed>  $updates
 */
function updatePartiesPage(Tenant $tenant, string|TestResponse $from, array $calls, array $updates = []): TestResponse
{
    $snapshot = $from instanceof TestResponse ? (string) $from->assertOk()->json('components.0.snapshot') : $from;

    $response = test()->withHeaders(['X-Livewire' => '1'])->postJson(
        $tenant->url(route('livewire.update', absolute: false)),
        ['components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => array_map(fn (array $call): array => [...$call, 'metadata' => []], $calls)]]],
    );

    test()->flushHeaders();

    return $response;
}

/**
 * Press Edit on a party of the page, then change the form and press Save in a second
 * request (property updates run before calls in one request, so Edit would reset them).
 *
 * @param  array<string, mixed>  $changes
 */
function editPartyOnPage(Tenant $tenant, int $partyId, array $changes): TestResponse
{
    $opened = livewireRequest($tenant, 'fcl/parties', [['method' => 'edit', 'params' => [$partyId]]]);

    return updatePartiesPage($tenant, $opened, [['method' => 'save', 'params' => []]], $changes);
}

/**
 * The validation errors of the component after a Livewire update.
 *
 * @return array<string, list<string>>
 */
function partyFormErrors(TestResponse $response): array
{
    return json_decode((string) $response->assertOk()->json('components.0.snapshot'), true)['memo']['errors'] ?? [];
}

beforeEach(function () {
    $this->tenant = provisionTenant('acme')->tenant;
});

test('a user allowed to view parties sees the list and the Operations menu, but cannot create or edit', function () {
    $partyId = $this->tenant->run(fn () => createParty(['legal_name' => 'Nile Imports S.A.E.', 'tax_number' => '100-200-300'])->id);
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView]);

    $this->get($this->tenant->url('dashboard'))->assertOk()->assertSee('data-test="sidebar-parties"', false);
    $this->get($this->tenant->url('fcl/parties'))
        ->assertOk()
        ->assertSee('Nile Imports S.A.E.')
        ->assertSee('100-200-300')
        ->assertDontSee('create-party-button')
        ->assertDontSee('wire:click="edit(', false);

    livewireRequest($this->tenant, 'fcl/parties', [['method' => 'create', 'params' => []]])->assertForbidden();
    livewireRequest($this->tenant, 'fcl/parties', [['method' => 'edit', 'params' => [$partyId]]])->assertForbidden();
    submitPartyForm($this->tenant, ['legalName' => 'Sneaky Party'])->assertOk();

    expect($this->tenant->run(fn () => Party::query()->where('legal_name', 'Sneaky Party')->exists()))->toBeFalse();
});

test('the page and the menu are refused without the view permission', function () {
    loginWithPartyPermissions($this->tenant, [TenantPermission::EmployeesView]);

    $this->get($this->tenant->url('employees'))->assertOk()->assertDontSee('data-test="sidebar-parties"', false);
    $this->get($this->tenant->url('fcl/parties'))->assertForbidden();
});

test('a company whose permissions are not synced yet keeps working, without the menu', function () {
    loginWithPartyPermissions($this->tenant, [TenantPermission::EmployeesView]);
    $this->tenant->run(fn () => Permission::findByName(TenantPermission::FclPartiesView->value, 'web')->delete());

    $this->get($this->tenant->url('dashboard'))->assertOk()->assertDontSee('data-test="sidebar-parties"', false);
    $this->get($this->tenant->url('fcl/parties'))->assertForbidden();
});

test('the parties page is recognised as FCL by its route and by its component name', function () {
    $route = app('router')->getRoutes()->getByName('fcl.parties.index');

    expect($route->gatherMiddleware())->toContain('impersonation.deny')
        ->and(RestrictionPolicy::deniesRoute($route))->toBeTrue()
        ->and(RestrictionPolicy::deniesComponent(app('livewire')->new('pages::fcl.parties.index')))->toBeTrue();
});

test('the actions refuse users without the manage permission', function (string $action) {
    tenancy()->initialize($this->tenant);
    $viewer = userWithPermissions([TenantPermission::FclPartiesView]);
    $party = createParty();

    expect(fn () => $action === 'create'
        ? app(CreateParty::class)->handle($viewer, ['legal_name' => 'Forbidden Co'])
        : app(UpdateParty::class)->handle($viewer, $party, ['legal_name' => 'Forbidden Co']))
        ->toThrow(AuthorizationException::class);

    expect(Party::query()->where('legal_name', 'Forbidden Co')->exists())->toBeFalse()
        ->and(TenantAuditLog::query()->count())->toBe(1);
})->with(['create', 'update']);

test('a party manager creates a party from the page; it is normalised and audited', function () {
    $manager = loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    $response = submitPartyForm($this->tenant, [
        'legalName' => '  Delta   Freight  Agents ',
        'taxNumber' => ' 512-334-901 ',
        'countryCode' => 'eg',
        'notes' => 'Origin agent for Shanghai',
    ]);

    expect(partyFormErrors($response))->toBe([]);

    tenancy()->initialize($this->tenant);
    $party = Party::query()->sole();
    $record = TenantAuditLog::query()->sole();

    expect($party->only(['legal_name', 'tax_number', 'country_code', 'notes', 'is_active', 'created_by']))->toBe([
        'legal_name' => 'Delta Freight Agents',
        'tax_number' => '512-334-901',
        'country_code' => 'EG',
        'notes' => 'Origin agent for Shanghai',
        'is_active' => true,
        'created_by' => $manager->id,
    ])
        ->and($record->action)->toBe(FclAuditAction::PartyCreated->value)
        ->and($record->subject_type)->toBe(Party::class)
        ->and($record->subject_id)->toBe($party->id)
        ->and($record->actor_user_id)->toBe($manager->id)
        ->and($record->correlation_id)->not->toBeNull()
        ->and($record->old_values)->toBeNull()
        // JSON columns do not keep the key order.
        ->and($record->new_values)->toEqual([
            'legal_name' => 'Delta Freight Agents',
            'tax_number' => '512-334-901',
            'country_code' => 'EG',
            'notes' => 'Origin agent for Shanghai',
            'is_active' => true,
        ])
        ->and($record->metadata)->toBeNull();
});

test('invalid party details are refused with the form field names', function (array $form, string $field) {
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    expect(array_keys(partyFormErrors(submitPartyForm($this->tenant, ['legalName' => 'Valid Name', ...$form]))))->toBe([$field])
        ->and($this->tenant->run(fn () => Party::query()->count()))->toBe(0);
})->with([
    'missing legal name' => [['legalName' => '   '], 'legalName'],
    'legal name too long' => [['legalName' => str_repeat('a', 256)], 'legalName'],
    'tax number too long' => [['taxNumber' => str_repeat('1', 51)], 'taxNumber'],
    'country is not a two-letter code' => [['countryCode' => 'EGY'], 'countryCode'],
    'country with digits' => [['countryCode' => 'E1'], 'countryCode'],
    'notes too long' => [['notes' => str_repeat('n', 2001)], 'notes'],
]);

test('the actions validate on their own, whoever calls them', function (array $attributes) {
    tenancy()->initialize($this->tenant);

    expect(fn () => app(CreateParty::class)->handle(tenantAdminUser(), $attributes))->toThrow(ValidationException::class)
        ->and(Party::query()->count())->toBe(0);
})->with([
    'no legal name' => [[]],
    'legal name is not a string' => [['legal_name' => ['Acme']]],
    'activity flag is not a boolean' => [['legal_name' => 'Acme', 'is_active' => 'maybe']],
]);

test('a likely duplicate is only a warning: nothing is saved until the user confirms', function (array $existing, array $new) {
    tenancy()->initialize($this->tenant);
    $first = createParty($existing);

    try {
        app(CreateParty::class)->handle(tenantAdminUser(), $new);
        $this->fail('A likely duplicate was saved without confirmation.');
    } catch (PossibleDuplicateParty $exception) {
        expect($exception->similarParties->modelKeys())->toBe([$first->id]);
    }

    expect(Party::query()->count())->toBe(1)
        ->and(TenantAuditLog::query()->count())->toBe(1);

    $second = app(CreateParty::class)->handle(tenantAdminUser(), $new, confirmedSimilarIds: [$first->id]);

    expect(Party::query()->count())->toBe(2)
        ->and(TenantAuditLog::query()->where('subject_id', $second->id)->sole()->metadata)
        ->toBe(['confirmed_despite_similar_party_ids' => [$first->id]]);
})->with([
    'same name, other case, spacing and punctuation' => [
        ['legal_name' => 'Acme Trading Co.', 'tax_number' => null],
        ['legal_name' => 'ACME  trading co', 'tax_number' => null],
    ],
    'same tax number, written differently' => [
        ['legal_name' => 'Acme Trading', 'tax_number' => '123-456-789'],
        ['legal_name' => 'Acme Egypt', 'tax_number' => '123 456 789'],
    ],
    'same Arabic name' => [
        ['legal_name' => 'شركة النيل للاستيراد', 'tax_number' => null],
        ['legal_name' => 'شركة  النيل للاستيراد', 'tax_number' => null],
    ],
    'Arabic name with diacritics, tatweel and letter variants' => [
        ['legal_name' => 'شركة الأمل للاستيراد', 'tax_number' => null],
        ['legal_name' => 'شَرِكه الامـل للإستيراد', 'tax_number' => null],
    ],
    'Latin name with and without accents' => [
        ['legal_name' => 'Société Générale', 'tax_number' => null],
        ['legal_name' => 'Societe Generale', 'tax_number' => null],
    ],
    'fullwidth letters' => [
        ['legal_name' => 'Acme Trading', 'tax_number' => null],
        ['legal_name' => 'ＡＣＭＥ Ｔｒａｄｉｎｇ', 'tax_number' => null],
    ],
    'tax number in Arabic-Indic digits' => [
        ['legal_name' => 'Nile Imports', 'tax_number' => '123-456-789'],
        ['legal_name' => 'شركة النيل', 'tax_number' => '١٢٣٤٥٦٧٨٩'],
    ],
]);

test('a confirmation covers only the similar parties that were shown', function () {
    tenancy()->initialize($this->tenant);
    $first = createParty(['legal_name' => 'Acme Trading', 'tax_number' => null]);

    // The warning listed $first; meanwhile a colleague adds another similar party.
    $second = createParty(['legal_name' => 'ACME Trading', 'tax_number' => null], confirmedSimilarIds: [$first->id]);

    try {
        app(CreateParty::class)->handle(tenantAdminUser(), ['legal_name' => 'acme trading'], confirmedSimilarIds: [$first->id]);
        $this->fail('A similar party the user never saw was confirmed.');
    } catch (PossibleDuplicateParty $exception) {
        expect($exception->similarParties->modelKeys())->toBe([$first->id, $second->id]);
    }

    expect(Party::query()->count())->toBe(2);
});

test('a name without letters or digits is not reported as a duplicate of every other one', function () {
    tenancy()->initialize($this->tenant);
    createParty(['legal_name' => '***', 'tax_number' => '---']);

    app(CreateParty::class)->handle(tenantAdminUser(), ['legal_name' => '...', 'tax_number' => '//']);

    expect(Party::query()->count())->toBe(2)
        ->and(Party::query()->orderBy('id')->pluck('tax_number_key')->all())->toBe([null, null]);
});

test('different companies are not reported as duplicates', function () {
    tenancy()->initialize($this->tenant);
    createParty(['legal_name' => 'Acme Trading', 'tax_number' => '111']);

    $party = app(CreateParty::class)->handle(tenantAdminUser(), ['legal_name' => 'Acme Trading Egypt', 'tax_number' => '222']);

    expect(Party::query()->count())->toBe(2)
        ->and(TenantAuditLog::query()->where('subject_id', $party->id)->sole()->metadata)->toBeNull();
});

test('the page shows the duplicate warning and saves only after "Save anyway"', function () {
    $this->tenant->run(fn () => createParty(['legal_name' => 'Acme Trading Co.', 'tax_number' => '900-100']));
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    $warned = submitPartyForm($this->tenant, ['legalName' => 'acme trading co'])
        ->assertOk()
        ->assertSee('This party may already exist')
        ->assertSee('Acme Trading Co. (900-100)')
        ->assertSee('Save anyway');

    expect($this->tenant->run(fn () => Party::query()->count()))->toBe(1);

    updatePartiesPage($this->tenant, $warned, [['method' => 'save', 'params' => [true]]])->assertOk()->assertSee('Party created.');

    expect($this->tenant->run(fn () => Party::query()->count()))->toBe(2);
});

test('"Save anyway" covers only the warning shown, not a name changed afterwards or a skipped warning', function () {
    $this->tenant->run(function (): void {
        createParty(['legal_name' => 'Acme Trading Co.', 'tax_number' => null]);
        createParty(['legal_name' => 'Pharaoh Logistics', 'tax_number' => null]);
    });
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    // Warned about Acme, the user types another existing name and presses "Save anyway".
    $warned = submitPartyForm($this->tenant, ['legalName' => 'acme trading co']);
    updatePartiesPage($this->tenant, $warned, [['method' => 'save', 'params' => [true]]], ['legalName' => 'pharaoh logistics'])
        ->assertOk()
        ->assertSee('This party may already exist');

    // A client sending the confirmation without ever being warned is warned first.
    livewireRequest($this->tenant, 'fcl/parties', [['method' => 'save', 'params' => [true]]], ['legalName' => 'acme trading co'])
        ->assertOk()
        ->assertSee('This party may already exist');

    expect($this->tenant->run(fn () => Party::query()->count()))->toBe(2);
});

test('an update audits only the changed values, and an unchanged save writes nothing', function () {
    tenancy()->initialize($this->tenant);
    $party = createParty(['legal_name' => 'Red Sea Lines', 'tax_number' => '555', 'country_code' => 'EG', 'notes' => null]);
    $admin = tenantAdminUser();

    app(UpdateParty::class)->handle($admin, $party, ['legal_name' => 'Red Sea Lines', 'tax_number' => '555', 'country_code' => 'eg']);

    expect(TenantAuditLog::query()->count())->toBe(1);

    app(UpdateParty::class)->handle($admin, $party, [
        'legal_name' => 'Red Sea Shipping Lines',
        'tax_number' => '555',
        'country_code' => 'EG',
        'notes' => 'Carrier',
        'is_active' => false,
    ]);

    $record = TenantAuditLog::query()->where('action', FclAuditAction::PartyUpdated->value)->sole();

    expect($party->refresh()->only(['legal_name', 'notes', 'is_active']))->toBe(['legal_name' => 'Red Sea Shipping Lines', 'notes' => 'Carrier', 'is_active' => false])
        ->and($party->name_key)->toBe('red sea shipping lines')
        ->and($record->subject_id)->toBe($party->id)
        ->and($record->old_values)->toEqual(['legal_name' => 'Red Sea Lines', 'notes' => null, 'is_active' => true])
        ->and($record->new_values)->toEqual(['legal_name' => 'Red Sea Shipping Lines', 'notes' => 'Carrier', 'is_active' => false]);
});

test('an update is checked for duplicates only when its name key or tax-number key changes', function () {
    tenancy()->initialize($this->tenant);
    $original = createParty(['legal_name' => 'Acme Trading', 'tax_number' => null]);
    $copy = createParty(['legal_name' => 'Acme Trading', 'tax_number' => null], confirmedSimilarIds: [$original->id]);
    $other = createParty(['legal_name' => 'Pharaoh Logistics', 'tax_number' => null]);
    $admin = tenantAdminUser();

    // The confirmed copy can still be edited, renamed by case or given a tax number
    // without a new confirmation: its similarity to the original was already accepted.
    app(UpdateParty::class)->handle($admin, $copy, ['notes' => 'Second account']);
    app(UpdateParty::class)->handle($admin, $copy, ['legal_name' => 'ACME Trading']);
    app(UpdateParty::class)->handle($admin, $copy, ['tax_number' => '444-555']);

    expect(fn () => app(UpdateParty::class)->handle($admin, $other, ['legal_name' => 'ACME trading']))
        ->toThrow(PossibleDuplicateParty::class)
        ->and($other->refresh()->legal_name)->toBe('Pharaoh Logistics');

    app(UpdateParty::class)->handle($admin, $other, ['legal_name' => 'ACME trading'], confirmedSimilarIds: [$original->id, $copy->id]);

    expect(TenantAuditLog::query()->where('subject_id', $other->id)->latest('id')->first()->metadata)
        ->toBe(['confirmed_despite_similar_party_ids' => [$original->id, $copy->id]]);
});

test('renaming a party on the page to an existing name warns, and "Save anyway" saves it', function () {
    [$otherId] = $this->tenant->run(function (): array {
        createParty(['legal_name' => 'Acme Trading', 'tax_number' => null]);

        return [createParty(['legal_name' => 'Pharaoh Logistics', 'tax_number' => null])->id];
    });
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    $warned = editPartyOnPage($this->tenant, $otherId, ['legalName' => 'acme trading'])
        ->assertOk()
        ->assertSee('This party may already exist');

    expect($this->tenant->run(fn () => Party::query()->findOrFail($otherId)->legal_name))->toBe('Pharaoh Logistics');

    updatePartiesPage($this->tenant, $warned, [['method' => 'save', 'params' => [true]]])->assertOk()->assertSee('Party updated.');

    expect($this->tenant->run(fn () => Party::query()->findOrFail($otherId)->legal_name))->toBe('acme trading');
});

test('an update keeps the attributes it is not given', function () {
    tenancy()->initialize($this->tenant);
    $party = createParty(['legal_name' => 'Delta Agents', 'tax_number' => '100-200', 'country_code' => 'EG', 'notes' => 'Origin agent', 'is_active' => false]);

    app(UpdateParty::class)->handle(tenantAdminUser(), $party, ['legal_name' => 'Delta Agents Ltd']);

    expect($party->refresh()->only(Party::EditableAttributes))->toBe([
        'legal_name' => 'Delta Agents Ltd',
        'tax_number' => '100-200',
        'country_code' => 'EG',
        'notes' => 'Origin agent',
        'is_active' => false,
    ])
        ->and(TenantAuditLog::query()->where('action', FclAuditAction::PartyUpdated->value)->sole()->new_values)
        ->toBe(['legal_name' => 'Delta Agents Ltd']);
});

test('updates are validated too, on the form and in the action', function () {
    $partyId = $this->tenant->run(fn () => createParty(['legal_name' => 'Valid Co'])->id);
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    expect(array_keys(partyFormErrors(editPartyOnPage($this->tenant, $partyId, ['legalName' => '   ']))))->toBe(['legalName']);

    tenancy()->initialize($this->tenant);

    expect(fn () => app(UpdateParty::class)->handle(tenantAdminUser(), Party::query()->findOrFail($partyId), ['legal_name' => str_repeat('a', 256)]))
        ->toThrow(ValidationException::class)
        ->and(Party::query()->sole()->legal_name)->toBe('Valid Co')
        ->and(TenantAuditLog::query()->where('action', FclAuditAction::PartyUpdated->value)->exists())->toBeFalse();
});

test('the warning list and the edited party cannot be changed by the browser', function (string $property, mixed $value) {
    $this->tenant->run(fn () => createParty(['legal_name' => 'Acme Trading', 'tax_number' => null]));
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    $response = livewireRequest($this->tenant, 'fcl/parties', [['method' => 'save', 'params' => [true]]], ['legalName' => 'acme trading', $property => $value]);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($this->tenant->run(fn () => Party::query()->pluck('legal_name')->all()))->toBe(['Acme Trading']);
})->with([
    'a forged warning list' => ['similarParties', [['id' => 1, 'legal_name' => 'Acme Trading', 'tax_number' => null, 'is_active' => true]]],
    'another party to edit' => ['editingId', 1],
]);

test('a party manager edits and deactivates a party from the page', function () {
    $partyId = $this->tenant->run(fn () => createParty(['legal_name' => 'Old Name Co'])->id);
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    editPartyOnPage($this->tenant, $partyId, ['legalName' => 'New Name Co', 'isActive' => false])
        ->assertOk()
        ->assertSee('Party updated.');

    expect($this->tenant->run(fn () => Party::query()->findOrFail($partyId)->only(['legal_name', 'is_active'])))
        ->toBe(['legal_name' => 'New Name Co', 'is_active' => false]);

    // Inactive parties leave the default list but stay on record.
    $this->get($this->tenant->url('fcl/parties'))->assertDontSee('New Name Co');
    $this->get($this->tenant->url('fcl/parties?status=inactive'))->assertSee('New Name Co');
    $this->get($this->tenant->url('fcl/parties?status='))->assertSee('New Name Co');
});

test('the list searches by name and by tax number, with or without separators', function (string $search) {
    $this->tenant->run(function (): void {
        createParty(['legal_name' => 'Alexandria Customs Brokers', 'tax_number' => '321-654-987']);
        createParty(['legal_name' => 'Cairo Haulage', 'tax_number' => '111-222-333']);
    });
    loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView]);

    $this->get($this->tenant->url('fcl/parties?search='.urlencode($search)))
        ->assertSee('Alexandria Customs Brokers')
        ->assertDontSee('Cairo Haulage');
})->with(['customs', '321-654', '321654987']);

test('parties are shared by every branch of the company', function () {
    tenancy()->initialize($this->tenant);
    $alexandria = extraBranch();
    createParty(['legal_name' => 'Shared Customer'], userInBranch(defaultBranch(), [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]));
    $colleague = userInBranch($alexandria, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);
    tenancy()->end();

    loginToTenant($this->tenant, $colleague);
    $this->get($this->tenant->url('fcl/parties'))->assertOk()->assertSee('Shared Customer');
});

test('a company never sees or edits the parties of another company, even with the same id', function () {
    $beta = provisionTenant('beta')->tenant;
    $acmeId = $this->tenant->run(fn () => createParty(['legal_name' => 'Acme Confidential Customer', 'tax_number' => '777'])->id);
    $betaId = $beta->run(fn () => createParty(['legal_name' => 'Beta Own Customer', 'tax_number' => '888'])->id);

    expect($acmeId)->toBe($betaId);

    loginWithPartyPermissions($beta, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    $this->get($beta->url('fcl/parties?status='))->assertOk()->assertSee('Beta Own Customer')->assertDontSee('Acme Confidential');
    $this->get($beta->url('fcl/parties?search=777'))->assertDontSee('Acme Confidential');

    editPartyOnPage($beta, $betaId, ['legalName' => 'Renamed by Beta'])->assertOk();

    expect($this->tenant->run(fn () => Party::query()->findOrFail($acmeId)->legal_name))->toBe('Acme Confidential Customer')
        ->and($beta->run(fn () => Party::query()->findOrFail($betaId)->legal_name))->toBe('Renamed by Beta')
        ->and($this->tenant->run(fn () => TenantAuditLog::query()->count()))->toBe(1);

    // A similar party of another company is no duplicate.
    $beta->run(fn () => createParty(['legal_name' => 'Acme Confidential Customer', 'tax_number' => '777']));
    expect($beta->run(fn () => Party::query()->count()))->toBe(2);
});

test('parties exist in the tenant databases only', function () {
    $this->tenant->run(fn () => createParty());

    expect(Schema::connection(config('tenancy.database.central_connection'))->hasTable('parties'))->toBeFalse()
        ->and($this->tenant->run(fn () => Schema::hasTable('parties')))->toBeTrue();
});

test('the parties page is blocked without an active subscription, like other operational pages', function () {
    $tenant = provisionTenant('lapsed', subscribed: false)->tenant;
    loginWithPartyPermissions($tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);

    $this->get($tenant->url('fcl/parties'))->assertRedirect($tenant->url('subscription-required'));
});

test('party audit records cannot be changed or deleted', function () {
    tenancy()->initialize($this->tenant);
    createParty();
    $record = TenantAuditLog::query()->sole();

    expect(fn () => $record->forceFill(['action' => 'forged'])->save())->toThrow(LogicException::class)
        ->and(fn () => $record->delete())->toThrow(LogicException::class)
        ->and(fn () => TenantAuditLog::query()->update(['reason' => 'forged']))->toThrow(LogicException::class);
});

test('during support access the parties page, its updates and the actions are refused', function () {
    enableSupportAccess();
    $partyId = $this->tenant->run(fn () => createParty(['legal_name' => 'Hidden From Support'])->id);

    // A page opened before the support session started.
    loginToTenant($this->tenant, $this->tenant->run(fn () => tenantAdminUser()));
    $snapshot = partiesPageSnapshot($this->tenant);

    [$session, $token] = startSupportSession(PlatformAdmin::factory()->create(), $this->tenant);
    postHandoff($this->tenant, $token)->assertRedirect();

    $this->get($this->tenant->url('dashboard'))->assertOk()->assertDontSee('data-test="sidebar-parties"', false);
    $this->get($this->tenant->url('fcl/parties'))->assertForbidden()->assertDontSee('Hidden From Support');
    updatePartiesPage($this->tenant, $snapshot, [['method' => 'save', 'params' => [true]]], ['legalName' => 'Support Write'])->assertForbidden();

    // The Gate layer, independently of the HTTP and Livewire layers.
    tenancy()->initialize($this->tenant);
    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));
    $admin = tenantAdminUser();

    expect(fn () => app(CreateParty::class)->handle($admin, ['legal_name' => 'Support Write']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateParty::class)->handle($admin, Party::query()->findOrFail($partyId), ['legal_name' => 'Support Write']))->toThrow(AuthorizationException::class)
        ->and(Party::query()->pluck('legal_name')->all())->toBe(['Hidden From Support'])
        ->and(SupportSessionEvent::query()->where('type', 'denied')->orderBy('id')->pluck('action')->all())
        // The update is refused by the page's "can:" check, which Livewire re-applies.
        ->toBe(['fcl.parties.index', 'livewire.update: pages::fcl.parties.index']);
});

test('during an employee view the parties page, its updates and the actions are refused, whatever the employee may do', function () {
    $employeeId = $this->tenant->run(fn (): int => userWithPermissions([TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage], ['email' => 'docs@acme.test'])->id);
    $partyId = $this->tenant->run(fn () => createParty(['legal_name' => 'Hidden From Views'])->id);

    // A page the Tenant Admin opened before starting the view.
    loginToTenant($this->tenant, $this->tenant->run(fn () => tenantAdminUser()));
    $snapshot = partiesPageSnapshot($this->tenant);
    startEmployeeView($this->tenant, $employeeId)->assertOk();

    $this->get($this->tenant->url('fcl/parties'))->assertForbidden()->assertDontSee('Hidden From Views');
    updatePartiesPage($this->tenant, $snapshot, [['method' => 'save', 'params' => []]], ['legalName' => 'View Write'])->assertForbidden();

    // The Gate layer, independently of the HTTP and Livewire layers.
    tenancy()->initialize($this->tenant);
    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation(ImpersonationSession::query()->sole()));
    $employee = User::query()->findOrFail($employeeId);

    expect(fn () => app(CreateParty::class)->handle($employee, ['legal_name' => 'View Write']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateParty::class)->handle($employee, Party::query()->findOrFail($partyId), ['legal_name' => 'View Write']))->toThrow(AuthorizationException::class)
        ->and(Party::query()->pluck('legal_name')->all())->toBe(['Hidden From Views'])
        ->and(ImpersonationEvent::query()->where('type', 'denied')->orderBy('id')->pluck('action')->all())->toBe(['fcl.parties.index', 'livewire.update: pages::fcl.parties.index']);
});

test('a permission taken away while the page is open is enforced on the next action', function () {
    $manager = loginWithPartyPermissions($this->tenant, [TenantPermission::FclPartiesView, TenantPermission::FclPartiesManage]);
    $snapshot = partiesPageSnapshot($this->tenant);
    $role = $this->tenant->run(fn () => User::query()->findOrFail($manager->id)->roles->sole());

    $this->tenant->run(fn () => $role->revokePermissionTo(TenantPermission::FclPartiesManage->value));

    updatePartiesPage($this->tenant, $snapshot, [['method' => 'save', 'params' => []]], ['legalName' => 'Late Write'])
        ->assertOk()
        ->assertSee('This action is unauthorized.');

    $this->tenant->run(fn () => $role->revokePermissionTo(TenantPermission::FclPartiesView->value));

    updatePartiesPage($this->tenant, $snapshot, [['method' => 'save', 'params' => []]], ['legalName' => 'Late Write'])->assertForbidden();

    expect($this->tenant->run(fn () => Party::query()->count()))->toBe(0);
});
