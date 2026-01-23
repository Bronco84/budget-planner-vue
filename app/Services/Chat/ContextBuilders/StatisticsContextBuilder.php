<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use Carbon\Carbon;

class StatisticsContextBuilder implements ContextBuilderInterface
{
    /**
     * Build statistics context based on the requested type.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $type = $options['type'] ?? 'monthly';

        return match ($type) {
            'monthly' => $this->buildMonthlyStatistics($budget),
            'yearly' => $this->buildYearlyStatistics($budget),
            default => $this->buildMonthlyStatistics($budget),
        };
    }

    /**
     * Build monthly statistics context.
     */
    protected function buildMonthlyStatistics(Budget $budget): array
    {
        $stats = $budget->getMonthlyStatistics();

        $byCategory = collect($stats['by_category'] ?? [])
            ->map(fn($cat) => [
                'category' => $cat->category ?? 'Uncategorized',
                'amount' => ($cat->total ?? 0) / 100,
            ])
            ->sortBy('amount')
            ->values()
            ->toArray();

        return [
            'period' => $stats['month_name'] . ' ' . $stats['year'],
            'total_income' => ($stats['total_income'] ?? 0) / 100,
            'total_expenses' => abs($stats['total_expenses'] ?? 0) / 100,
            'net' => (($stats['total_income'] ?? 0) + ($stats['total_expenses'] ?? 0)) / 100,
            'by_category' => $byCategory,
            'vs_last_month' => [
                'income_change_percent' => round($stats['income_change'] ?? 0, 1),
                'expenses_change_percent' => round($stats['expenses_change'] ?? 0, 1),
                'previous_month' => $stats['prev_month_name'] ?? null,
            ],
        ];
    }

    /**
     * Build yearly statistics context.
     */
    protected function buildYearlyStatistics(Budget $budget): array
    {
        $year = now()->year;
        $stats = $budget->getYearlyStatistics($year);

        $monthlyData = [];
        foreach ($stats['monthly'] ?? [] as $monthName => $monthStats) {
            $monthlyData[] = [
                'month' => $monthName,
                'income' => ($monthStats['total_income'] ?? 0) / 100,
                'expenses' => abs($monthStats['total_expenses'] ?? 0) / 100,
                'net' => (($monthStats['total_income'] ?? 0) + ($monthStats['total_expenses'] ?? 0)) / 100,
            ];
        }

        $yearlyTotals = $stats['yearly_totals'] ?? [];

        return [
            'year' => $year,
            'monthly_breakdown' => $monthlyData,
            'yearly_totals' => [
                'total_income' => ($yearlyTotals['income'] ?? 0) / 100,
                'total_expenses' => abs($yearlyTotals['expenses'] ?? 0) / 100,
                'net' => (($yearlyTotals['income'] ?? 0) + ($yearlyTotals['expenses'] ?? 0)) / 100,
            ],
            'vs_last_year' => [
                'income_change_percent' => round($yearlyTotals['income_change'] ?? 0, 1),
                'expenses_change_percent' => round($yearlyTotals['expenses_change'] ?? 0, 1),
            ],
        ];
    }

    /**
     * Get the context type identifier(s).
     */
    public function getContextType(): array
    {
        return ['statistics_monthly', 'statistics_yearly'];
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        // Monthly: ~200 tokens, Yearly: ~400 tokens
        return 300; // Average estimate
    }
}
