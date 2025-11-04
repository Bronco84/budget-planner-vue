<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\Budget;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<\App\Models\Transaction>, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'account_id' => Account::factory(),
            'description' => fake()->words(3, true),
            'category' => fake()->randomElement(['Groceries', 'Dining', 'Transportation', 'Entertainment', 'Utilities', 'Healthcare', 'Shopping', 'Income', 'Other']),
            'amount_in_cents' => fake()->numberBetween(-50000, 50000),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the transaction is income.
     */
    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_in_cents' => fake()->numberBetween(100000, 500000),
            'category' => 'Income',
        ]);
    }

    /**
     * Indicate that the transaction is an expense.
     */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_in_cents' => fake()->numberBetween(-50000, -100),
            'category' => fake()->randomElement(['Groceries', 'Dining', 'Transportation', 'Entertainment', 'Utilities', 'Healthcare', 'Shopping']),
        ]);
    }

    /**
     * Set the transaction date to a specific date.
     */
    public function forDate($date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    /**
     * Set the transaction to this month.
     */
    public function thisMonth(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => fake()->dateTimeBetween('first day of this month', 'last day of this month'),
        ]);
    }

    /**
     * Set the transaction to last month.
     */
    public function lastMonth(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => fake()->dateTimeBetween('first day of last month', 'last day of last month'),
        ]);
    }
}
