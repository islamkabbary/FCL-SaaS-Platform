<?php

namespace App\Exceptions\Fcl;

use App\Models\Fcl\Party;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Parties with the same name or tax number already exist. A warning, not a rule: the
 * action succeeds when the user confirms that the party is a different one.
 */
class PossibleDuplicateParty extends RuntimeException
{
    /**
     * @param  Collection<int, Party>  $similarParties
     */
    public function __construct(public readonly Collection $similarParties)
    {
        parent::__construct(__('A party with the same name or tax number already exists.'));
    }
}
