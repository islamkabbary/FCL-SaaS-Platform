<?php

namespace App\Enums\Fcl;

/**
 * Actions of FCL Operations recorded in the tenant audit log (TenantAuditLog::record).
 */
enum FclAuditAction: string
{
    case PartyCreated = 'fcl.party.created';
    case PartyUpdated = 'fcl.party.updated';

    /**
     * Get the human readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::PartyCreated => __('Party created'),
            self::PartyUpdated => __('Party updated'),
        };
    }
}
