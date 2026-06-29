<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingScreenshot extends Model
{
    protected $fillable = ['trade_id', 'disk', 'path', 'original_name', 'caption'];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
