<?php

namespace App\Enums;

enum TradeStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Closed = 'closed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Open => 'blue', self::Partial => 'amber', self::Closed => 'zinc'
        };
    }
}
