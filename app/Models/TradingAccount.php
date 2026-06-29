<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingAccount extends Model
{
    protected $fillable = ['name', 'initial_balance', 'current_balance', 'is_active'];

    protected function casts(): array
    {
        return ['initial_balance' => 'decimal:2', 'current_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function equitySnapshots(): HasMany
    {
        return $this->hasMany(EquitySnapshot::class);
    }

    public function accountTransactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }
}
