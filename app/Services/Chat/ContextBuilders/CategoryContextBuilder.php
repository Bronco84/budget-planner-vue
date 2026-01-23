<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use Carbon\Carbon;

class CategoryContextBuilder implements ContextBuilderInterface
{
    /**
     * Build category/budget allocation context.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $categories = $budget->categories()
            ->orderBy('order')
            ->get()
            ->map(function ($category) use ($budget, $startOfMonth, $endOfMonth) {
                // Calculate spent amount for this month
                $spent = abs($budget->transactions()
                    ->where('category', $category->name)
                    ->where('amount_in_cents', '<', 0)
                    ->whereBetween('date', [$startOfMonth, $endOfMonth])
                    ->sum('amount_in_cents')) / 100;

                $allocated = (float) $category->amount;
                $remaining = $allocated - $spent;
                $percentUsed = $allocated > 0 ? round(($spent / $allocated) * 100, 1) : 0;

                return [
                    'name' => $category->name,
                    'allocated' => $allocated,
                    'spent' => $spent,
                    'remaining' => $remaining,
                    'percent_used' => $percentUsed,
                    'is_over_budget' => $remaining < 0,
                ];
            })
            ->toArray();

        // Calculate totals
        $totalAllocated = collect($categories)->sum('allocated');
        $totalSpent = collect($categories)->sum('spent');
        $categoriesOverBudget = collect($categories)->where('is_over_budget', true)->count();

        return [
            'period' => Carbon::now()->format('F Y'),
            'categories' => $categories,
            'summary' => [
                'total_allocated' => $totalAllocated,
                'total_spent' => $totalSpent,
                'total_remaining' => $totalAllocated - $totalSpent,
                'category_count' => count($categories),
                'categories_over_budget' => $categoriesOverBudget,
                'overall_percent_used' => $totalAllocated > 0 ? round(($totalSpent / $totalAllocated) * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'categories';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        $categoryCount = $budget->categories()->count();
        // ~30 tokens per category + 40 for summary
        return ($categoryCount * 30) + 40;
    }
}
