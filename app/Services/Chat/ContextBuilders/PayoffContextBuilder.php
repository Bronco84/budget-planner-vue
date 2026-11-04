<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use App\Services\PayoffPlanService;
use Illuminate\Support\Facades\Log;

class PayoffContextBuilder implements ContextBuilderInterface
{
    public function __construct(
        protected PayoffPlanService $payoffPlanService
    ) {}

    /**
     * Build payoff plans context.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $payoffPlans = $budget->payoffPlans()
            ->with(['debts.account'])
            ->get()
            ->map(function ($plan) {
                $debts = $plan->debts->map(function ($debt) {
                    return [
                        'account' => $debt->account?->name ?? 'Unknown',
                        'balance' => $debt->starting_balance_cents / 100,
                        'interest_rate' => $debt->interest_rate,
                        'minimum_payment' => $debt->minimum_payment_cents / 100,
                        'priority' => $debt->priority,
                    ];
                })->toArray();

                $totalDebt = collect($debts)->sum('balance');
                $totalMinimumPayments = collect($debts)->sum('minimum_payment');

                // Calculate projection if possible
                $projection = null;
                $projectionFailed = false;
                try {
                    $projectionData = $this->payoffPlanService->calculatePayoffProjection($plan);
                    if (! empty($projectionData['timeline'])) {
                        $lastMonth = end($projectionData['timeline']);
                        $projection = [
                            'months_to_payoff' => count($projectionData['timeline']),
                            'projected_payoff_date' => $plan->start_date?->copy()->addMonths(count($projectionData['timeline']))->format('Y-m'),
                            'total_interest_paid' => ($lastMonth['total_interest_paid'] ?? 0) / 100,
                        ];
                    }
                } catch (\Exception $e) {
                    // Continue without it, but say it failed rather than that there is none.
                    $projectionFailed = true;
                    Log::warning('Chat payoff context: projection failed for plan', [
                        'payoff_plan_id' => $plan->id,
                        'budget_id' => $plan->budget_id,
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }

                return [
                    'name' => $plan->name,
                    'strategy' => $this->formatStrategy($plan->strategy),
                    'monthly_extra_payment' => ($plan->monthly_extra_payment_cents ?? 0) / 100,
                    'is_active' => $plan->is_active,
                    'start_date' => $plan->start_date?->format('Y-m-d'),
                    'debts' => $debts,
                    'total_debt' => $totalDebt,
                    'total_minimum_payments' => $totalMinimumPayments,
                    'projection' => $projection,
                    'projection_unavailable' => $projectionFailed,
                ];
            })
            ->toArray();

        $activePlan = collect($payoffPlans)->firstWhere('is_active', true);

        return [
            'payoff_plans' => $payoffPlans,
            'active_plan' => $activePlan ? $activePlan['name'] : null,
            'summary' => [
                'total_plans' => count($payoffPlans),
                'total_debt_across_plans' => collect($payoffPlans)->sum('total_debt'),
            ],
        ];
    }

    /**
     * Format strategy name for display.
     */
    protected function formatStrategy(?string $strategy): string
    {
        return match ($strategy) {
            'snowball' => 'Debt Snowball (smallest balance first)',
            'avalanche' => 'Debt Avalanche (highest interest first)',
            'custom' => 'Custom priority',
            default => $strategy ?? 'Unknown',
        };
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'payoff_plans';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        $planCount = $budget->payoffPlans()->count();

        // ~100 tokens per plan + 20 for summary
        return ($planCount * 100) + 20;
    }
}
