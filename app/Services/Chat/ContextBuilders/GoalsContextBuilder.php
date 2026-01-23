<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use Carbon\Carbon;

class GoalsContextBuilder implements ContextBuilderInterface
{
    /**
     * Build financial goals context.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        // Get goals from payoff plans
        $goals = $budget->payoffPlans()
            ->with('goals')
            ->get()
            ->flatMap(function ($plan) {
                return $plan->goals->map(function ($goal) {
                    $targetAmount = $goal->target_amount_cents / 100;
                    $monthlyContribution = $goal->monthly_contribution_cents / 100;
                    
                    // Calculate progress (this would need actual tracking in a real implementation)
                    // For now, estimate based on months since creation
                    $monthsSinceCreation = $goal->created_at->diffInMonths(now());
                    $estimatedProgress = min($monthsSinceCreation * $monthlyContribution, $targetAmount);
                    $percentComplete = $targetAmount > 0 ? round(($estimatedProgress / $targetAmount) * 100, 1) : 0;
                    
                    // Calculate months to goal
                    $remaining = $targetAmount - $estimatedProgress;
                    $monthsToGoal = $monthlyContribution > 0 ? ceil($remaining / $monthlyContribution) : null;

                    return [
                        'name' => $goal->name,
                        'description' => $goal->description,
                        'type' => $goal->goal_type,
                        'target_amount' => $targetAmount,
                        'monthly_contribution' => $monthlyContribution,
                        'estimated_progress' => round($estimatedProgress, 2),
                        'percent_complete' => $percentComplete,
                        'target_date' => $goal->target_date?->format('Y-m-d'),
                        'months_to_goal' => $monthsToGoal,
                    ];
                });
            })
            ->toArray();

        return [
            'goals' => $goals,
            'summary' => [
                'total_goals' => count($goals),
                'total_target_amount' => collect($goals)->sum('target_amount'),
                'total_monthly_contributions' => collect($goals)->sum('monthly_contribution'),
                'average_progress' => count($goals) > 0 
                    ? round(collect($goals)->avg('percent_complete'), 1) 
                    : 0,
            ],
        ];
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'goals';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        $goalCount = $budget->payoffPlans()
            ->with('goals')
            ->get()
            ->flatMap(fn($p) => $p->goals)
            ->count();

        // ~50 tokens per goal + 30 for summary
        return ($goalCount * 50) + 30;
    }
}
