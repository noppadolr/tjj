<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class TradeExit extends Model
{
    use SoftDeletes;

    protected $fillable = ['trade_id', 'exit_date', 'exit_time', 'exit_price', 'exit_contracts', 'gross_profit', 'commission', 'vat', 'total_cost', 'net_profit'];

    protected function casts(): array
    {
        return ['exit_date' => 'date', 'exit_price' => 'decimal:2', 'gross_profit' => 'decimal:2', 'commission' => 'decimal:2', 'vat' => 'decimal:2', 'total_cost' => 'decimal:2', 'net_profit' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return HasOne<TradeCommission, $this>
     */
    public function tradeCommission(): HasOne
    {
        return $this->hasOne(TradeCommission::class);
    }

    /**
     * @return HasOne<EquitySnapshot, $this>
     */
    public function equitySnapshot(): HasOne
    {
        return $this->hasOne(EquitySnapshot::class);
    }
}
