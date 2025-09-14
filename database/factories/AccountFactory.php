<?php

namespace Database\Factories;

use App\Models\Budget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'name' => $this->faker->randomElement(['Checking', 'Savings', 'Credit Card']) . ' Account',
            'type' => $this->faker->randomElement(['checking', 'savings', 'credit_card']),
            'current_balance_cents' => $this->faker->numberBetween(-10000, 100000), // -$100 to $1000
            'balance_updated_at' => $this->faker->dateTimeThisMonth(),
            'include_in_budget' => true,
        ];
    }
}
