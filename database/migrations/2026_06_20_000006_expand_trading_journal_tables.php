<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 50)->unique();
            $table->string('name')->nullable();
            $table->decimal('multiplier', 10, 2)->default(200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('commission_rates', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('trading_account_id')->constrained()->nullOnDelete();
        });

        $symbols = DB::table('commission_rates')->pluck('contract')
            ->merge(DB::table('trades')->pluck('contract'))
            ->filter()
            ->map(fn ($symbol) => strtoupper(trim($symbol)))
            ->unique();

        foreach ($symbols as $symbol) {
            $contractId = DB::table('contracts')->insertGetId([
                'symbol' => $symbol,
                'name' => $symbol,
                'multiplier' => 200,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('commission_rates')->whereRaw('UPPER(contract) = ?', [$symbol])->update(['contract_id' => $contractId]);
            DB::table('trades')->whereRaw('UPPER(contract) = ?', [$symbol])->update(['contract_id' => $contractId]);
        }

        Schema::create('trade_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_exit_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('commission_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('commission', 15, 2)->default(0);
            $table->decimal('vat', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->timestamps();
        });

        foreach (DB::table('trade_exits')->whereNull('deleted_at')->get() as $exit) {
            DB::table('trade_commissions')->insert([
                'trade_exit_id' => $exit->id,
                'commission' => $exit->commission,
                'vat' => $exit->vat,
                'total_cost' => $exit->total_cost,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('trading_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->date('note_date');
            $table->text('content');
            $table->timestamps();
            $table->index('note_date');
        });

        Schema::create('trading_screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('caption')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_screenshots');
        Schema::dropIfExists('trading_notes');
        Schema::dropIfExists('trade_commissions');

        Schema::table('trades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });

        Schema::table('commission_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });

        Schema::dropIfExists('contracts');
    }
};
