<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;

class AccountContextBuilder implements ContextBuilderInterface
{
    /**
     * Build basic account context.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $accounts = $budget->accounts()
            ->get()
            ->map(function ($account) {
                return [
                    'name' => $account->name,
                    'type' => $account->type,
                    'balance' => $account->current_balance_cents / 100,
                    'institution' => $account->institution_name,
                    'is_liability' => $account->isLiability(),
                    'include_in_budget' => $account->include_in_budget,
                    'exclude_from_total' => $account->exclude_from_total_balance,
                ];
            })
            ->toArray();

        // Calculate summary totals
        $totalAssets = collect($accounts)
            ->filter(fn($a) => !$a['is_liability'] && !$a['exclude_from_total'])
            ->sum('balance');

        $totalLiabilities = collect($accounts)
            ->filter(fn($a) => $a['is_liability'] && !$a['exclude_from_total'])
            ->sum(fn($a) => abs($a['balance']));

        $netWorth = $totalAssets - $totalLiabilities;

        return [
            'accounts' => $accounts,
            'summary' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'net_worth' => $netWorth,
                'account_count' => count($accounts),
            ],
        ];
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'accounts';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        $accountCount = $budget->accounts()->count();
        // ~50 tokens per account + 30 for summary
        return ($accountCount * 50) + 30;
    }
}
