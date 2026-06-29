<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRate extends Model
{
    protected $fillable = ['contract_id', 'broker_name', 'contract', 'commission_per_contract', 'vat_percent', 'effective_date', 'is_active'];

    protected function casts(): array
    {
        return ['commission_per_contract' => 'decimal:2', 'vat_percent' => 'decimal:2', 'effective_date' => 'date', 'is_active' => 'boolean'];
    }

    public function contractDefinition(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function tradeCommissions(): HasMany
    {
        return $this->hasMany(TradeCommission::class);
    }
}
