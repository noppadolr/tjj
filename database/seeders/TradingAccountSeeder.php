<?php

namespace Database\Seeders;

use App\Models\TradingAccount;
use Illuminate\Database\Seeder;

class TradingAccountSeeder extends Seeder
{
    public function run(): void
    {
        TradingAccount::updateOrCreate(['name' => 'Main Account'], ['initial_balance' => 100000, 'current_balance' => 100000, 'is_active' => true]);
    }
}
