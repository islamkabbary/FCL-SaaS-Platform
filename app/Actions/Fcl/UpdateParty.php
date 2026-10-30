<?php

namespace App\Actions\Fcl;

use App\Enums\Fcl\FclAuditAction;
use App\Exceptions\Fcl\PossibleDuplicateParty;
use App\Models\Fcl\Party;
use App\Models\TenantAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Change a party of the company's FCL master data, including deactivating it, audited in
 * the tenant database with the old and new values of the changed attributes only.
 *
 * Attributes left out keep their current values. A change that makes the party look like
 * other parties (a new name key or tax-number key matching them) needs the same
 * confirmation as creating it: $confirmedSimilarIds are the ids the user was shown.
 * Saving without changes writes nothing.
 */
class UpdateParty
{
    public function __construct(protected ValidatePartyAttributes $validatePartyAttributes) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $confirmedSimilarIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws PossibleDuplicateParty
     */
    public function handle(User $actor, Party $party, array $attributes, array $confirmedSimilarIds = []): Party
    {
        Gate::forUser($actor)->authorize('update', $party);

        return DB::transaction(function () use ($actor, $party, $attributes, $confirmedSimilarIds): Party {
            // The current values, so omitted attributes are kept and the audit's old values
            // are the ones being replaced.
            $party = Party::query()->lockForUpdate()->whereKey($party->getKey())->firstOrFail();
            $before = $party->auditedValues();

            $party->fill($this->validatePartyAttributes->handle([...$before, ...Arr::only($attributes, Party::EditableAttributes)]));

            if (! $party->isDirty(Party::EditableAttributes)) {
                return $party;
            }

            // Only a key that changes can make the party look like other parties; matches
            // on an unchanged key were already accepted when that key was saved.
            $nameKey = Party::nameKey($party->legal_name);
            $taxNumberKey = $party->tax_number === null ? '' : Party::taxNumberKey($party->tax_number);
            $similar = Party::similarToKeys(
                $nameKey === $party->getOriginal('name_key') ? '' : $nameKey,
                $taxNumberKey === (string) $party->getOriginal('tax_number_key') ? '' : $taxNumberKey,
                ignoreId: $party->id,
            );

            if (array_diff($similar->modelKeys(), $confirmedSimilarIds) !== []) {
                throw new PossibleDuplicateParty($similar);
            }

            $changed = array_keys(Arr::only($party->getDirty(), Party::EditableAttributes));
            $party->save();

            TenantAuditLog::record(
                FclAuditAction::PartyUpdated,
                $actor,
                $party,
                oldValues: Arr::only($before, $changed),
                newValues: Arr::only($party->auditedValues(), $changed),
                metadata: $similar->isEmpty() ? null : ['confirmed_despite_similar_party_ids' => $similar->modelKeys()],
            );

            return $party;
        });
    }
}
