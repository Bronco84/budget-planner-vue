<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;

class RecurringContextBuilder implements ContextBuilderInterface
{
    /**
     * Build recurring transactions context.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $recurringTransactions = $budget->recurringTransactionTemplates()
            ->with('account')
            ->orderBy('description')
            ->get()
            ->map(function ($template) {
                $amount = $template->amount_in_cents / 100;
                
                return [
                    'description' => $template->friendly_label ?: $template->description,
                    'amount' => $amount,
                    'frequency' => $this->formatFrequency($template->frequency),
                    'next_date' => $template->start_date?->format('Y-m-d'),
                    'category' => $template->category ?: 'Uncategorized',
                    'account' => $template->account?->name ?? 'Unknown',
                    'is_expense' => $amount < 0,
                    'is_dynamic' => $template->is_dynamic_amount,
                ];
            })
            ->toArray();

        // Calculate summaries
        $monthlyIncome = collect($recurringTransactions)
            ->filter(fn($t) => !$t['is_expense'])
            ->sum(fn($t) => $this->toMonthlyAmount($t['amount'], $t['frequency']));

        $monthlyExpenses = collect($recurringTransactions)
            ->filter(fn($t) => $t['is_expense'])
            ->sum(fn($t) => abs($this->toMonthlyAmount($t['amount'], $t['frequency'])));

        return [
            'recurring_transactions' => $recurringTransactions,
            'summary' => [
                'total_count' => count($recurringTransactions),
                'income_count' => collect($recurringTransactions)->where('is_expense', false)->count(),
                'expense_count' => collect($recurringTransactions)->where('is_expense', true)->count(),
                'estimated_monthly_income' => round($monthlyIncome, 2),
                'estimated_monthly_expenses' => round($monthlyExpenses, 2),
                'estimated_monthly_net' => round($monthlyIncome - $monthlyExpenses, 2),
            ],
        ];
    }

    /**
     * Format frequency for display.
     */
    protected function formatFrequency(string $frequency): string
    {
        return match ($frequency) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'biweekly' => 'Every 2 weeks',
            'monthly' => 'Monthly',
            'bimonthly' => 'Twice a month',
            'quarterly' => 'Quarterly',
            'yearly' => 'Yearly',
            default => $frequency,
        };
    }

    /**
     * Convert an amount to monthly equivalent.
     */
    protected function toMonthlyAmount(float $amount, string $frequency): float
    {
        return match ($frequency) {
            'Daily' => $amount * 30,
            'Weekly' => $amount * 4.33,
            'Every 2 weeks' => $amount * 2.17,
            'Monthly' => $amount,
            'Twice a month' => $amount * 2,
            'Quarterly' => $amount / 3,
            'Yearly' => $amount / 12,
            default => $amount,
        };
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'recurring';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        $count = $budget->recurringTransactionTemplates()->count();
        // ~25 tokens per recurring item + 40 for summary
        return ($count * 25) + 40;
    }
}
