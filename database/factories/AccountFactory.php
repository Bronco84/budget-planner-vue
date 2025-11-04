<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Budget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<\App\Models\Account>, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'name' => fake()->words(2, true) . ' Account',
            'type' => fake()->randomElement(['checking', 'savings', 'credit card', 'investment', 'cash']),
            'current_balance_cents' => fake()->numberBetween(-100000, 500000),
            'balance_updated_at' => now(),
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
        ];
    }

    /**
     * Indicate that the account is a checking account.
     */
    public function checking(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'checking',
        ]);
    }

    /**
     * Indicate that the account is a savings account.
     */
    public function savings(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'savings',
        ]);
    }

    /**
     * Indicate that the account is a credit card.
     */
    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'credit card',
        ]);
    }
}
