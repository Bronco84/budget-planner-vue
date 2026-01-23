<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use Carbon\Carbon;

class TransactionContextBuilder implements ContextBuilderInterface
{
    /**
     * Maximum transactions to include to avoid token overflow.
     */
    protected const MAX_TRANSACTIONS = 50;

    /**
     * Build transaction context based on the requested type.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $type = $options['type'] ?? 'recent';

        return match ($type) {
            'recent' => $this->buildRecentTransactions($budget),
            'month' => $this->buildMonthTransactions($budget),
            'by_category' => $this->buildByCategory($budget),
            default => $this->buildRecentTransactions($budget),
        };
    }

    /**
     * Build context for recent transactions (last 30 days).
     */
    protected function buildRecentTransactions(Budget $budget): array
    {
        $startDate = Carbon::now()->subDays(30);
        
        $transactions = $budget->transactions()
            ->with('account')
            ->where('date', '>=', $startDate)
            ->orderBy('date', 'desc')
            ->limit(self::MAX_TRANSACTIONS)
            ->get()
            ->map(fn($t) => $this->formatTransaction($t))
            ->toArray();

        $totalIncome = collect($transactions)->where('amount', '>', 0)->sum('amount');
        $totalExpenses = abs(collect($transactions)->where('amount', '<', 0)->sum('amount'));

        return [
            'period' => 'Last 30 days',
            'transactions' => $transactions,
            'summary' => [
                'transaction_count' => count($transactions),
                'total_income' => $totalIncome,
                'total_expenses' => $totalExpenses,
                'net' => $totalIncome - $totalExpenses,
            ],
        ];
    }

    /**
     * Build context for current month transactions.
     */
    protected function buildMonthTransactions(Budget $budget): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        
        $transactions = $budget->transactions()
            ->with('account')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->orderBy('date', 'desc')
            ->limit(self::MAX_TRANSACTIONS)
            ->get()
            ->map(fn($t) => $this->formatTransaction($t))
            ->toArray();

        $totalIncome = collect($transactions)->where('amount', '>', 0)->sum('amount');
        $totalExpenses = abs(collect($transactions)->where('amount', '<', 0)->sum('amount'));

        return [
            'period' => Carbon::now()->format('F Y'),
            'transactions' => $transactions,
            'summary' => [
                'transaction_count' => count($transactions),
                'total_income' => $totalIncome,
                'total_expenses' => $totalExpenses,
                'net' => $totalIncome - $totalExpenses,
            ],
        ];
    }

    /**
     * Build context for transactions grouped by category.
     */
    protected function buildByCategory(Budget $budget): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        
        $transactions = $budget->transactions()
            ->where('date', '>=', $startOfMonth)
            ->get();

        $byCategory = $transactions
            ->groupBy('category')
            ->map(function ($categoryTransactions, $category) {
                $total = $categoryTransactions->sum('amount_in_cents') / 100;
                return [
                    'category' => $category ?: 'Uncategorized',
                    'total' => $total,
                    'transaction_count' => $categoryTransactions->count(),
                    'is_expense' => $total < 0,
                ];
            })
            ->sortBy('total')  // Sort by amount (expenses first, then income)
            ->values()
            ->toArray();

        $totalIncome = collect($byCategory)->where('is_expense', false)->sum('total');
        $totalExpenses = abs(collect($byCategory)->where('is_expense', true)->sum('total'));

        return [
            'period' => Carbon::now()->format('F Y'),
            'by_category' => $byCategory,
            'summary' => [
                'category_count' => count($byCategory),
                'total_income' => $totalIncome,
                'total_expenses' => $totalExpenses,
                'net' => $totalIncome - $totalExpenses,
            ],
        ];
    }

    /**
     * Format a single transaction for context.
     */
    protected function formatTransaction($transaction): array
    {
        return [
            'date' => $transaction->date->format('Y-m-d'),
            'description' => $transaction->description,
            'amount' => $transaction->amount_in_cents / 100,
            'category' => $transaction->category ?: 'Uncategorized',
            'account' => $transaction->account?->name ?? 'Unknown',
        ];
    }

    /**
     * Get the context type identifier(s).
     */
    public function getContextType(): array
    {
        return ['transactions_recent', 'transactions_month', 'transactions_by_category'];
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        // ~25 tokens per transaction, assume ~30 transactions average
        return 750;
    }
}
