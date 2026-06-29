<?php

namespace App\Enums;

enum PositionType: string
{
    case Long = 'long';
    case Short = 'short';

    public function label(): string
    {
        return $this === self::Long ? 'Long' : 'Short';
    }

    public function badgeColor(): string
    {
        return $this === self::Long ? 'green' : 'red';
    }
}
