<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use Carbon\Carbon;

/**
 * Flexible, on-demand transaction query for the tool-calling assistant.
 *
 * Unlike TransactionContextBuilder (which only exposes fixed recent/month/by_category
 * views), this builder accepts an arbitrary date range plus optional category, account,
 * and description filters. All queries are hard-bounded to the last 12 months so a
 * single tool call can never pull an unbounded history into the model.
 */
class TransactionQueryContextBuilder implements ContextBuilderInterface
{
    /**
     * Default number of transactions returned when the caller omits a limit.
     */
    protected const DEFAULT_LIMIT = 25;

    /**
     * Hard cap on the number of transactions a single query may return.
     */
    protected const MAX_LIMIT = 100;

    /**
     * How far back queries are allowed to reach.
     */
    protected const MAX_MONTHS_BACK = 12;

    /**
     * Build a filtered transaction list for the budget.
     *
     * Supported options: start_date, end_date (Y-m-d), category, account_id,
     * search, limit.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $now = Carbon::now();
        $earliestAllowed = $now->copy()->subMonths(self::MAX_MONTHS_BACK)->startOfDay();

        $start = $this->parseDate($options['start_date'] ?? null, $earliestAllowed)->startOfDay();
        if ($start->lt($earliestAllowed)) {
            $start = $earliestAllowed->copy();
        }

        $end = $this->parseDate($options['end_date'] ?? null, $now)->endOfDay();
        if ($end->lt($start)) {
            $end = $start->copy()->endOfDay();
        }

        $limit = (int) ($options['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query = $budget->transactions()
            ->with('account')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        $filters = [];

        if (! empty($options['category'])) {
            $query->where('category', 'like', '%'.$options['category'].'%');
            $filters['category'] = $options['category'];
        }

        if (! empty($options['account_id'])) {
            $accountId = (int) $options['account_id'];
            // Only honor an account filter that actually belongs to this budget.
            if ($budget->accounts()->whereKey($accountId)->exists()) {
                $query->where('account_id', $accountId);
                $filters['account_id'] = $accountId;
            }
        }

        if (! empty($options['search'])) {
            $query->where('description', 'like', '%'.$options['search'].'%');
            $filters['search'] = $options['search'];
        }

        $totalMatching = (clone $query)->count();

        $transactions = $query
            ->orderBy('date', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($t) => $this->formatTransaction($t))
            ->toArray();

        $totalIncome = collect($transactions)->where('amount', '>', 0)->sum('amount');
        $totalExpenses = abs(collect($transactions)->where('amount', '<', 0)->sum('amount'));

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'filters' => $filters,
            'transactions' => $transactions,
            'summary' => [
                'returned' => count($transactions),
                'total_matching' => $totalMatching,
                'truncated' => $totalMatching > count($transactions),
                'total_income' => round($totalIncome, 2),
                'total_expenses' => round($totalExpenses, 2),
                'net' => round($totalIncome - $totalExpenses, 2),
            ],
        ];
    }

    /**
     * Parse a Y-m-d date string, falling back to the provided default on any bad input.
     */
    protected function parseDate(?string $value, Carbon $default): Carbon
    {
        if (empty($value)) {
            return $default->copy();
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return $default->copy();
        }
    }

    /**
     * Format a single transaction for the model (amounts in dollars).
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
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'transactions_query';
    }

    /**
     * Estimate token count (~25 tokens per transaction, default page size).
     */
    public function getTokenEstimate(Budget $budget): int
    {
        return self::DEFAULT_LIMIT * 25;
    }
}
