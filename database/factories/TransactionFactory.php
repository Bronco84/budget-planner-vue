<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Budget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
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
            'account_id' => Account::factory(),
            'description' => $this->faker->company() . ' Purchase',
            'category' => $this->faker->randomElement([
                'Food & Dining', 'Transportation', 'Shopping', 'Entertainment', 
                'Bills & Utilities', 'Income', 'Transfer', 'Healthcare'
            ]),
            'amount_in_cents' => $this->faker->numberBetween(-50000, 10000), // -$500 to $100
            'date' => $this->faker->dateTimeThisMonth(),
            'plaid_transaction_id' => null,
            'airtable_transaction_id' => null,
            'airtable_account_id' => null,
            'is_plaid_imported' => false,
            'is_airtable_imported' => false,
            'airtable_metadata' => null,
        ];
    }

    /**
     * Indicate that the transaction is imported from Airtable.
     */
    public function airtableImported(): static
    {
        return $this->state(fn (array $attributes) => [
            'airtable_transaction_id' => 'rec' . $this->faker->regexify('[A-Za-z0-9]{14}'),
            'airtable_account_id' => 'rec' . $this->faker->regexify('[A-Za-z0-9]{14}'),
            'is_airtable_imported' => true,
            'airtable_metadata' => [
                'id' => 'rec' . $this->faker->regexify('[A-Za-z0-9]{14}'),
                'fields' => [
                    'description' => $attributes['description'],
                    'amount' => $attributes['amount_in_cents'] / 100,
                    'date' => $attributes['date']->format('Y-m-d'),
                ],
                'createdTime' => $this->faker->dateTimeThisMonth()->format('c')
            ]
        ]);
    }

    /**
     * Indicate that the transaction is imported from Plaid.
     */
    public function plaidImported(): static
    {
        return $this->state(fn (array $attributes) => [
            'plaid_transaction_id' => $this->faker->regexify('[A-Za-z0-9]{26}'),
            'is_plaid_imported' => true,
        ]);
    }
}
