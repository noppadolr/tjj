<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('equity_snapshots', function (Blueprint $table) {
            $table->foreignId('account_transaction_id')
                ->nullable()
                ->after('trade_exit_id')
                ->unique()
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('equity_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_transaction_id');
        });
    }
};
