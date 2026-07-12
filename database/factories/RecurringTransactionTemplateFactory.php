<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RecurringTransactionTemplate>
 */
class RecurringTransactionTemplateFactory extends Factory
{
    protected $model = RecurringTransactionTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'account_id' => null,
            'description' => fake()->words(2, true),
            'category' => fake()->optional()->word(),
            'amount_in_cents' => fake()->numberBetween(-500000, 500000),
            'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
            'start_date' => now()->subMonths(fake()->numberBetween(1, 12)),
            'end_date' => null,
        ];
    }
}
