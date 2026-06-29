<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->string('contract', 50);
            $table->string('position_type', 20);
            $table->unsignedInteger('total_contracts');
            $table->decimal('entry_price', 12, 2);
            $table->date('entry_date');
            $table->time('entry_time')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['trade_date', 'contract']);
            $table->index('status');
            $table->index('position_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
