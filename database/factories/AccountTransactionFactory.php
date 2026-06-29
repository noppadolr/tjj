<?php

namespace Database\Factories;

use App\Models\AccountTransaction;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountTransaction>
 */
class AccountTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trading_account_id' => fn () => TradingAccount::query()->value('id')
                ?? TradingAccount::create([
                    'name' => 'Factory Account',
                    'initial_balance' => 100000,
                    'current_balance' => 100000,
                    'is_active' => true,
                ])->id,
            'type' => $this->faker->randomElement(['deposit', 'withdrawal']),
            'amount' => $this->faker->randomFloat(2, 1000, 50000),
            'transaction_date' => $this->faker->date(),
            'note' => $this->faker->optional()->sentence(),
        ];
    }
}
