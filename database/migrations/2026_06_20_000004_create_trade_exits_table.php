<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->date('exit_date');
            $table->time('exit_time')->nullable();
            $table->decimal('exit_price', 12, 2);
            $table->unsignedInteger('exit_contracts');
            $table->decimal('gross_profit', 15, 2)->default(0);
            $table->decimal('commission', 15, 2)->default(0);
            $table->decimal('vat', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('exit_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_exits');
    }
};
