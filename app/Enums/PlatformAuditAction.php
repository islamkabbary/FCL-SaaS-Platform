<?php

namespace App\Enums;

enum PlatformAuditAction: string
{
    case TenantCreated = 'tenant.created';
    case TenantUpdated = 'tenant.updated';
    case TenantSuspended = 'tenant.suspended';
    case TenantReactivated = 'tenant.reactivated';
    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';
    case PlanArchived = 'plan.archived';
    case PlanActivated = 'plan.activated';
    case SubscriptionActivated = 'subscription.activated';
    case SubscriptionRenewed = 'subscription.renewed';
    case SeatIncreaseRequested = 'seats.increase_requested';
    case SeatReductionRequested = 'seats.reduction_requested';
    case SeatRequestCancelled = 'seats.request_cancelled';
    case SeatRequestApproved = 'seats.request_approved';
    case SeatRequestRejected = 'seats.request_rejected';
    case ChargeCreated = 'billing.charge_created';
    case PaymentRecorded = 'billing.payment_recorded';
    case TwoFactorEnabled = 'admin.two_factor_enabled';
    case TwoFactorDisabled = 'admin.two_factor_disabled';
    case TwoFactorRecoveryCodesRegenerated = 'admin.recovery_codes_regenerated';
    case TwoFactorReset = 'admin.two_factor_reset';

    /**
     * Get the human readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::TenantCreated => __('Tenant created'),
            self::TenantUpdated => __('Details updated'),
            self::TenantSuspended => __('Access suspended'),
            self::TenantReactivated => __('Access reactivated'),
            self::PlanCreated => __('Plan created'),
            self::PlanUpdated => __('Plan updated'),
            self::PlanArchived => __('Plan archived'),
            self::PlanActivated => __('Plan activated'),
            self::SubscriptionActivated => __('Subscription activated'),
            self::SubscriptionRenewed => __('Subscription renewed'),
            self::SeatIncreaseRequested => __('Seat increase requested'),
            self::SeatReductionRequested => __('Seat reduction requested'),
            self::SeatRequestCancelled => __('Seat request cancelled'),
            self::SeatRequestApproved => __('Seat request approved'),
            self::SeatRequestRejected => __('Seat request rejected'),
            self::ChargeCreated => __('Charge created'),
            self::PaymentRecorded => __('Payment recorded'),
            self::TwoFactorEnabled => __('Two-factor authentication enabled'),
            self::TwoFactorDisabled => __('Two-factor authentication disabled'),
            self::TwoFactorRecoveryCodesRegenerated => __('Recovery codes regenerated'),
            self::TwoFactorReset => __('Two-factor authentication reset'),
        };
    }

    /**
     * Get the badge color for the action.
     */
    public function color(): string
    {
        return match ($this) {
            self::TenantCreated => 'blue',
            self::TenantUpdated => 'zinc',
            self::TenantSuspended => 'red',
            self::TenantReactivated => 'green',
            self::PlanCreated, self::PlanUpdated, self::PlanArchived, self::PlanActivated => 'zinc',
            self::SubscriptionActivated, self::SubscriptionRenewed, self::SeatRequestApproved, self::PaymentRecorded => 'green',
            self::SeatIncreaseRequested, self::SeatReductionRequested, self::ChargeCreated => 'blue',
            self::SeatRequestCancelled, self::SeatRequestRejected => 'amber',
            self::TwoFactorEnabled, self::TwoFactorRecoveryCodesRegenerated => 'zinc',
            self::TwoFactorDisabled, self::TwoFactorReset => 'red',
        };
    }
}
