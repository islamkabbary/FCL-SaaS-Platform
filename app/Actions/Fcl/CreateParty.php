<?php

namespace App\Actions\Fcl;

use App\Enums\Fcl\FclAuditAction;
use App\Exceptions\Fcl\PossibleDuplicateParty;
use App\Models\Fcl\Party;
use App\Models\TenantAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Add a party to the company's FCL master data, audited in the tenant database.
 *
 * A party with the same name or tax number as existing ones is only created once the
 * user has confirmed those very parties: $confirmedSimilarIds are the ids of the similar
 * parties the user was shown. A similar party the user has not seen (added meanwhile)
 * gives a new warning. The confirmation is recorded.
 */
class CreateParty
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
    public function handle(User $actor, array $attributes, array $confirmedSimilarIds = []): Party
    {
        Gate::forUser($actor)->authorize('create', Party::class);

        $values = $this->validatePartyAttributes->handle($attributes);

        return DB::transaction(function () use ($actor, $values, $confirmedSimilarIds): Party {
            $similar = Party::similarTo($values['legal_name'], $values['tax_number']);

            if (array_diff($similar->modelKeys(), $confirmedSimilarIds) !== []) {
                throw new PossibleDuplicateParty($similar);
            }

            $party = new Party;
            $party->forceFill([...$values, 'created_by' => $actor->getKey()])->save();

            TenantAuditLog::record(
                FclAuditAction::PartyCreated,
                $actor,
                $party,
                newValues: $party->auditedValues(),
                metadata: $similar->isEmpty() ? null : ['confirmed_despite_similar_party_ids' => $similar->modelKeys()],
            );

            return $party;
        });
    }
}
