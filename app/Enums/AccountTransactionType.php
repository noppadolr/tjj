<?php

namespace App\Enums;

enum AccountTransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Deposit => 'green',
            self::Withdrawal => 'red',
        };
    }

    public function signedAmount(float $amount): float
    {
        return $this === self::Deposit ? $amount : -$amount;
    }
}
