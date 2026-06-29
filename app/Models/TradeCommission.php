<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeCommission extends Model
{
    protected $fillable = ['trade_exit_id', 'commission_rate_id', 'commission', 'vat', 'total_cost'];

    protected function casts(): array
    {
        return ['commission' => 'decimal:2', 'vat' => 'decimal:2', 'total_cost' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<TradeExit, $this>
     */
    public function tradeExit(): BelongsTo
    {
        return $this->belongsTo(TradeExit::class);
    }

    /**
     * @return BelongsTo<CommissionRate, $this>
     */
    public function commissionRate(): BelongsTo
    {
        return $this->belongsTo(CommissionRate::class);
    }
}
