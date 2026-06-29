<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equity_snapshots', function (Blueprint $table) {
            $table->foreignId('trade_exit_id')->nullable()->after('trade_id')->unique()->constrained()->nullOnDelete();
        });

        $usedExitIds = [];

        foreach (DB::table('equity_snapshots')->whereNotNull('trade_id')->orderBy('id')->get() as $snapshot) {
            $exit = DB::table('trade_exits')
                ->where('trade_id', $snapshot->trade_id)
                ->whereDate('exit_date', $snapshot->snapshot_date)
                ->whereNotIn('id', $usedExitIds ?: [0])
                ->orderByRaw('ABS(net_profit - ?)', [$snapshot->net_profit])
                ->orderBy('id')
                ->first();

            if ($exit) {
                DB::table('equity_snapshots')->where('id', $snapshot->id)->update(['trade_exit_id' => $exit->id]);
                $usedExitIds[] = $exit->id;
            }
        }
    }

    public function down(): void
    {
        Schema::table('equity_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trade_exit_id');
        });
    }
};
