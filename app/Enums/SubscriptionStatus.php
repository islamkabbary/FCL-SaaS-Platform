<?php

namespace App\Enums;

/**
 * A tenant's subscription state on a given day. Derived from the subscription terms,
 * never stored, and independent of the provisioning status and of manual suspension.
 */
enum SubscriptionStatus: string
{
    /** Created before subscriptions existed and not transitioned yet: access as before, no seat limit. */
    case Legacy = 'legacy';

    /** Requires a subscription but none has been activated yet. */
    case None = 'none';

    /** A term covers the day. */
    case Active = 'active';

    /** The last term ended and no term covers the day. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Legacy => __('Legacy (no subscription)'),
            self::None => __('No subscription'),
            self::Active => __('Active'),
            self::Expired => __('Expired'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Legacy => 'zinc',
            self::None => 'amber',
            self::Active => 'green',
            self::Expired => 'red',
        };
    }

    /**
     * Whether the tenant's users may use ordinary tenant functionality.
     */
    public function allowsAccess(): bool
    {
        return $this === self::Legacy || $this === self::Active;
    }
}
