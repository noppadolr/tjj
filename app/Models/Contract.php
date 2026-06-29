<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    protected $fillable = ['symbol', 'name', 'multiplier', 'is_active'];

    protected function casts(): array
    {
        return ['multiplier' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function commissionRates(): HasMany
    {
        return $this->hasMany(CommissionRate::class);
    }
}
