<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ScenarioAdjustment>
 */
class ScenarioAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['one_time_expense', 'recurring_expense', 'debt_paydown', 'savings_contribution'];
        $type = fake()->randomElement($types);
        $isRecurring = $type !== 'one_time_expense';
        
        return [
            'scenario_id' => Scenario::factory(),
            'account_id' => Account::factory(),
            'adjustment_type' => $type,
            'amount_in_cents' => fake()->numberBetween(-100000, 100000),
            'start_date' => now()->addDays(fake()->numberBetween(1, 30)),
            'end_date' => $isRecurring ? now()->addMonths(fake()->numberBetween(6, 36)) : null,
            'frequency' => $isRecurring ? fake()->randomElement(['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly']) : null,
            'day_of_week' => null,
            'day_of_month' => $isRecurring ? fake()->numberBetween(1, 28) : null,
            'description' => fake()->optional()->sentence(),
            'target_recurring_template_id' => null,
        ];
    }

    /**
     * Indicate that this is a one-time expense.
     */
    public function oneTime(): static
    {
        return $this->state(fn (array $attributes) => [
            'adjustment_type' => 'one_time_expense',
            'end_date' => null,
            'frequency' => null,
            'day_of_week' => null,
            'day_of_month' => null,
        ]);
    }

    /**
     * Indicate that this is a recurring expense.
     */
    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'adjustment_type' => 'recurring_expense',
            'frequency' => 'monthly',
            'day_of_month' => fake()->numberBetween(1, 28),
        ]);
    }

    /**
     * Indicate that this is a monthly adjustment.
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
            'day_of_month' => fake()->numberBetween(1, 28),
        ]);
    }
}
