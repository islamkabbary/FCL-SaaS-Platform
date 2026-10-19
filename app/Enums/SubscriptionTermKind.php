<?php

namespace App\Enums;

enum SubscriptionTermKind: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';

    public function label(): string
    {
        return match ($this) {
            self::Initial => __('Initial term'),
            self::Renewal => __('Renewal'),
        };
    }
}
