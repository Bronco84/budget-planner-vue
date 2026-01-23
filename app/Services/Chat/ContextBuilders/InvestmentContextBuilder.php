<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;

class InvestmentContextBuilder implements ContextBuilderInterface
{
    /**
     * Build investment holdings context.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        // Get investment accounts with their holdings
        $investmentAccounts = $budget->accounts()
            ->with(['plaidAccount.holdings.security'])
            ->get()
            ->filter(function ($account) {
                // Filter to investment-type accounts
                return in_array($account->type, [
                    'brokerage',
                    'traditional ira',
                    'roth ira',
                    '401k',
                    '403b',
                    '457b',
                    'stock plan',
                    'investment',
                ]);
            });

        $holdings = [];
        $totalValue = 0;

        foreach ($investmentAccounts as $account) {
            $accountHoldings = $account->plaidAccount?->holdings ?? collect();
            
            foreach ($accountHoldings as $holding) {
                $security = $holding->security;
                $value = $holding->value_cents / 100;
                $totalValue += $value;

                $holdings[] = [
                    'account' => $account->name,
                    'name' => $security?->name ?? $holding->name ?? 'Unknown Security',
                    'ticker' => $security?->ticker_symbol,
                    'type' => $security?->type ?? 'unknown',
                    'quantity' => $holding->quantity,
                    'price' => ($holding->price_cents ?? 0) / 100,
                    'value' => $value,
                    'cost_basis' => $holding->cost_basis_cents ? $holding->cost_basis_cents / 100 : null,
                ];
            }

            // If no holdings data but account has a balance, include the account total
            if ($accountHoldings->isEmpty() && $account->current_balance_cents > 0) {
                $value = $account->current_balance_cents / 100;
                $totalValue += $value;
                
                $holdings[] = [
                    'account' => $account->name,
                    'name' => $account->name . ' (Total)',
                    'ticker' => null,
                    'type' => 'account_total',
                    'quantity' => null,
                    'price' => null,
                    'value' => $value,
                    'cost_basis' => null,
                ];
            }
        }

        // Group holdings by type for summary
        $byType = collect($holdings)
            ->groupBy('type')
            ->map(fn($group) => [
                'type' => $group->first()['type'],
                'count' => $group->count(),
                'total_value' => $group->sum('value'),
            ])
            ->values()
            ->toArray();

        return [
            'holdings' => $holdings,
            'total_value' => round($totalValue, 2),
            'by_type' => $byType,
            'summary' => [
                'total_holdings' => count($holdings),
                'total_accounts' => $investmentAccounts->count(),
                'total_value' => round($totalValue, 2),
            ],
        ];
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'investments';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        // Investment context is typically ~150 tokens
        return 150;
    }
}
