<?php

namespace App\Enums;

enum ImpersonationKind: string
{
    /** A Platform Admin acting as a Super Admin. */
    case Support = 'support';

    /** A Super Admin viewing the application as one of their employees. */
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::Support => __('Platform support'),
            self::Employee => __('Super Admin'),
        };
    }
}
