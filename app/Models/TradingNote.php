<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingNote extends Model
{
    protected $fillable = ['trade_id', 'note_date', 'content'];

    protected function casts(): array
    {
        return ['note_date' => 'date'];
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
