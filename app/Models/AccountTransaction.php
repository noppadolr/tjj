<?php

namespace App\Models;

use App\Enums\AccountTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccountTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\AccountTransactionFactory> */
    use HasFactory;

    protected $fillable = ['trading_account_id', 'type', 'amount', 'transaction_date', 'note'];

    protected function casts(): array
    {
        return [
            'type' => AccountTransactionType::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    public function equitySnapshot(): HasOne
    {
        return $this->hasOne(EquitySnapshot::class);
    }

    public function signedAmount(): float
    {
        return $this->type->signedAmount((float) $this->amount);
    }
}
