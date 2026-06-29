<?php

namespace Database\Seeders;

use App\Models\Contract;
use Illuminate\Database\Seeder;

class ContractSeeder extends Seeder
{
    public function run(): void
    {
        Contract::updateOrCreate(
            ['symbol' => 'S50'],
            ['name' => 'SET50 Index Futures', 'multiplier' => 200, 'is_active' => true],
        );
    }
}
