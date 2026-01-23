<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;

class PropertyContextBuilder implements ContextBuilderInterface
{
    /**
     * Build real estate/property context.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $properties = $budget->properties()
            ->with('linkedAccounts')
            ->get()
            ->map(function ($property) {
                $currentValue = $property->current_value_cents / 100;
                
                // Calculate total mortgage balance from linked accounts
                $linkedMortgages = $property->linkedAccounts
                    ->filter(fn($account) => in_array($account->type, ['mortgage', 'loan']))
                    ->map(function ($account) {
                        return [
                            'name' => $account->name,
                            'balance' => abs($account->current_balance_cents / 100),
                            'type' => $account->type,
                        ];
                    });

                $totalMortgageBalance = $linkedMortgages->sum('balance');
                $equity = $currentValue - $totalMortgageBalance;
                $equityPercent = $currentValue > 0 ? round(($equity / $currentValue) * 100, 1) : 0;

                return [
                    'name' => $property->name,
                    'type' => $property->property_type,
                    'address' => $property->address,
                    'current_value' => $currentValue,
                    'purchase_price' => $property->purchase_price_cents ? $property->purchase_price_cents / 100 : null,
                    'purchase_date' => $property->purchase_date?->format('Y-m-d'),
                    'linked_mortgages' => $linkedMortgages->values()->toArray(),
                    'total_mortgage_balance' => $totalMortgageBalance,
                    'equity' => $equity,
                    'equity_percent' => $equityPercent,
                ];
            })
            ->toArray();

        $totalPropertyValue = collect($properties)->sum('current_value');
        $totalMortgages = collect($properties)->sum('total_mortgage_balance');
        $totalEquity = collect($properties)->sum('equity');

        return [
            'properties' => $properties,
            'summary' => [
                'total_properties' => count($properties),
                'total_property_value' => $totalPropertyValue,
                'total_mortgage_balance' => $totalMortgages,
                'total_equity' => $totalEquity,
                'average_equity_percent' => count($properties) > 0 
                    ? round(collect($properties)->avg('equity_percent'), 1) 
                    : 0,
            ],
        ];
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'properties';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        $propertyCount = $budget->properties()->count();
        // ~60 tokens per property + 30 for summary
        return ($propertyCount * 60) + 30;
    }
}
