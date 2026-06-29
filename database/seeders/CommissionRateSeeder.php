<?php

namespace Database\Seeders;

use App\Models\CommissionRate;
use App\Models\Contract;
use Illuminate\Database\Seeder;

class CommissionRateSeeder extends Seeder
{
    public function run(): void
    {
        $contract = Contract::where('symbol', 'S50')->firstOrFail();

        CommissionRate::updateOrCreate(
            ['contract' => 'S50', 'effective_date' => today()],
            ['contract_id' => $contract->id, 'broker_name' => 'Default Broker', 'commission_per_contract' => 80, 'vat_percent' => 7, 'is_active' => true],
        );
    }
}
