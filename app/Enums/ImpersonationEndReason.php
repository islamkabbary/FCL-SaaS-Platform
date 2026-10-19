<?php

namespace App\Enums;

enum ImpersonationEndReason: string
{
    case EndedByActor = 'ended_by_actor';
    case LoggedOut = 'logged_out';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case TenantSuspended = 'tenant_suspended';
    case SubscriptionInactive = 'subscription_inactive';
    case TargetDeactivated = 'target_deactivated';
    case TargetIneligible = 'target_ineligible';
    case ActorUnauthorized = 'actor_unauthorized';
    case FeatureDisabled = 'feature_disabled';
    case SessionMismatch = 'session_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::EndedByActor => __('Ended by the initiator'),
            self::LoggedOut => __('Logged out'),
            self::Expired => __('Time limit reached'),
            self::Revoked => __('Revoked'),
            self::TenantSuspended => __('Company access suspended'),
            self::SubscriptionInactive => __('Subscription not active'),
            self::TargetDeactivated => __('Target account deactivated'),
            self::TargetIneligible => __('Target account no longer eligible'),
            self::ActorUnauthorized => __('Initiator no longer authorized'),
            self::FeatureDisabled => __('Feature disabled'),
            self::SessionMismatch => __('Session check failed'),
        };
    }

    /**
     * The resulting status of a session ended for this reason.
     */
    public function status(): ImpersonationStatus
    {
        return match ($this) {
            self::Expired => ImpersonationStatus::Expired,
            self::Revoked => ImpersonationStatus::Revoked,
            default => ImpersonationStatus::Ended,
        };
    }
}
