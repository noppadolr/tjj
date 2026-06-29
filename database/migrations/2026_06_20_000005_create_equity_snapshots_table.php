<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('balance_before', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->date('snapshot_date');
            $table->timestamps();
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_snapshots');
    }
};
