<?php

namespace App\Models;

use App\Enums\PositionType;
use App\Enums\TradeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trade extends Model
{
    use SoftDeletes;

    protected $fillable = ['trading_account_id', 'contract_id', 'trade_date', 'contract', 'position_type', 'total_contracts', 'entry_price', 'entry_date', 'entry_time', 'status', 'note'];

    protected function casts(): array
    {
        return ['trade_date' => 'date', 'entry_date' => 'date', 'entry_price' => 'decimal:2', 'position_type' => PositionType::class, 'status' => TradeStatus::class];
    }

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contractDefinition(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /**
     * @return HasMany<TradeExit, $this>
     */
    public function exits(): HasMany
    {
        return $this->hasMany(TradeExit::class);
    }

    /**
     * @return HasMany<EquitySnapshot, $this>
     */
    public function equitySnapshots(): HasMany
    {
        return $this->hasMany(EquitySnapshot::class);
    }

    /**
     * @return HasMany<TradingNote, $this>
     */
    public function tradingNotes(): HasMany
    {
        return $this->hasMany(TradingNote::class);
    }

    /**
     * @return HasMany<TradingScreenshot, $this>
     */
    public function tradingScreenshots(): HasMany
    {
        return $this->hasMany(TradingScreenshot::class);
    }

    public function closedContracts(): int
    {
        return (int) ($this->relationLoaded('exits')
            ? $this->exits->sum('exit_contracts')
            : $this->exits()->sum('exit_contracts'));
    }

    public function remainingContracts(): int
    {
        return max(0, $this->total_contracts - $this->closedContracts());
    }
}
