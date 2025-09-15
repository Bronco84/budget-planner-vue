<?php

namespace Database\Factories;

use App\Models\RecurringTransactionTemplate;
use App\Models\Budget;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class RecurringTransactionTemplateFactory extends Factory
{
    protected $model = RecurringTransactionTemplate::class;

    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'account_id' => Account::factory(),
            'description' => $this->faker->randomElement([
                'Netflix Subscription',
                'Spotify Premium',
                'Electric Bill',
                'Water Bill',
                'Internet Service',
                'Phone Bill',
                'Gym Membership',
                'Insurance Payment',
                'Mortgage Payment',
                'Car Payment',
            ]),
            'amount_in_cents' => $this->faker->numberBetween(-50000, -1000), // Negative amounts for expenses
            'category' => $this->faker->randomElement([
                'Utilities',
                'Entertainment',
                'Transportation',
                'Insurance',
                'Housing',
                'Subscriptions',
            ]),
            'frequency' => $this->faker->randomElement(['weekly', 'biweekly', 'monthly']),
            'day_of_month' => $this->faker->numberBetween(1, 28),
            'day_of_week' => $this->faker->numberBetween(0, 6),
            'week_of_month' => null,
            'start_date' => Carbon::now()->subMonths($this->faker->numberBetween(1, 12)),
            'end_date' => null, // Most recurring transactions don't have an end date
        ];
    }

    /**
     * Create a monthly template
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
            'day_of_week' => null,
            'day_of_month' => $this->faker->numberBetween(1, 28),
        ]);
    }

    /**
     * Create a weekly template
     */
    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'weekly',
            'day_of_month' => null,
            'day_of_week' => $this->faker->numberBetween(0, 6),
        ]);
    }

    /**
     * Create a biweekly template
     */
    public function biweekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'biweekly',
            'day_of_month' => null,
            'day_of_week' => $this->faker->numberBetween(0, 6),
        ]);
    }

    /**
     * Create an expense template (negative amount)
     */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_in_cents' => $this->faker->numberBetween(-50000, -500),
        ]);
    }

    /**
     * Create an income template (positive amount)
     */
    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_in_cents' => $this->faker->numberBetween(100000, 1000000), // $1,000 - $10,000
            'description' => $this->faker->randomElement([
                'Salary Deposit',
                'Freelance Payment',
                'Bonus Payment',
                'Investment Income',
                'Side Hustle',
            ]),
            'category' => 'Income',
        ]);
    }

    /**
     * Create a template with a specific future date
     */
    public function futureDate(Carbon $date): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_month' => $date->day,
            'start_date' => $date->copy()->subMonths(2),
        ]);
    }

    /**
     * Create a template that ends soon
     */
    public function endingSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'end_date' => Carbon::now()->addWeeks($this->faker->numberBetween(1, 4)),
        ]);
    }
}
