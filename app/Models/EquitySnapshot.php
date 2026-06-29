<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquitySnapshot extends Model
{
    protected $fillable = ['trading_account_id', 'trade_id', 'trade_exit_id', 'account_transaction_id', 'balance_before', 'net_profit', 'balance_after', 'snapshot_date'];

    protected function casts(): array
    {
        return ['balance_before' => 'decimal:2', 'net_profit' => 'decimal:2', 'balance_after' => 'decimal:2', 'snapshot_date' => 'date'];
    }

    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function tradeExit(): BelongsTo
    {
        return $this->belongsTo(TradeExit::class);
    }

    public function accountTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class);
    }
}
