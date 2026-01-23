<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\RecurringTransactionService;
use Carbon\Carbon;

class ProjectionsContextBuilder implements ContextBuilderInterface
{
    public function __construct(
        protected RecurringTransactionService $recurringService,
        protected BudgetService $budgetService
    ) {}

    /**
     * Build projections context including future balances and cash flow.
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $monthsToProject = $options['months'] ?? 3;
        $startDate = Carbon::now();
        $endDate = Carbon::now()->addMonths($monthsToProject);

        // Get account projections
        $accountProjections = $this->buildAccountProjections($budget, $startDate, $endDate);
        
        // Get upcoming transactions
        $upcomingTransactions = $this->buildUpcomingTransactions($budget, $startDate, $endDate);
        
        // Get autopay projections
        $autopayProjections = $this->buildAutopayProjections($budget, $startDate, $endDate);
        
        // Calculate monthly cash flow
        $monthlyCashFlow = $this->calculateMonthlyCashFlow($budget);

        return [
            'projection_period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
                'months' => $monthsToProject,
            ],
            'monthly_cash_flow' => $monthlyCashFlow,
            'account_projections' => $accountProjections,
            'upcoming_transactions' => $upcomingTransactions,
            'autopay_projections' => $autopayProjections,
        ];
    }

    /**
     * Build projected balances for each account.
     */
    protected function buildAccountProjections(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        $projections = [];

        foreach ($budget->accounts as $account) {
            $currentBalance = $account->current_balance_cents / 100;
            
            // Get projected transactions for this account
            $accountTransactions = $this->recurringService->projectTransactions(
                $account,
                $startDate,
                $endDate
            );

            // Calculate monthly projected balances
            $monthlyBalances = [];
            $runningBalance = $currentBalance;
            
            $currentMonth = $startDate->copy()->startOfMonth();
            while ($currentMonth <= $endDate) {
                $monthEnd = $currentMonth->copy()->endOfMonth();
                
                // Sum transactions for this month
                $monthTransactions = $accountTransactions->filter(function ($t) use ($currentMonth, $monthEnd) {
                    $date = Carbon::parse($t->date ?? $t['date'] ?? now());
                    return $date >= $currentMonth && $date <= $monthEnd;
                });
                
                $monthNet = $monthTransactions->sum(function ($t) {
                    return ($t->amount_in_cents ?? $t['amount_in_cents'] ?? 0) / 100;
                });
                
                $runningBalance += $monthNet;
                
                $monthlyBalances[] = [
                    'month' => $currentMonth->format('F Y'),
                    'date' => $currentMonth->format('Y-m-01'),
                    'projected_balance' => round($runningBalance, 2),
                    'change' => round($monthNet, 2),
                ];
                
                $currentMonth->addMonth();
            }

            $projections[] = [
                'account' => $account->name,
                'type' => $account->type,
                'current_balance' => $currentBalance,
                'projected_balances' => $monthlyBalances,
                'final_projected_balance' => $runningBalance,
                'total_change' => round($runningBalance - $currentBalance, 2),
            ];
        }

        return $projections;
    }

    /**
     * Build list of upcoming transactions.
     */
    protected function buildUpcomingTransactions(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        $transactions = [];
        $limit = 20; // Limit to avoid token overflow

        foreach ($budget->accounts as $account) {
            $accountTransactions = $this->recurringService->projectTransactions(
                $account,
                $startDate,
                $endDate->copy()->addDays(30) // Look ahead a bit more
            );

            foreach ($accountTransactions->take($limit) as $transaction) {
                $transactions[] = [
                    'date' => Carbon::parse($transaction->date ?? $transaction['date'] ?? now())->format('Y-m-d'),
                    'description' => $transaction->description ?? $transaction['description'] ?? 'Unknown',
                    'amount' => ($transaction->amount_in_cents ?? $transaction['amount_in_cents'] ?? 0) / 100,
                    'account' => $account->name,
                    'type' => $transaction->type ?? 'recurring',
                ];
            }
        }

        // Sort by date and limit
        usort($transactions, fn($a, $b) => strcmp($a['date'], $b['date']));
        
        return array_slice($transactions, 0, $limit);
    }

    /**
     * Build autopay projections for credit cards.
     */
    protected function buildAutopayProjections(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        try {
            $autopayProjections = $this->recurringService->generateAutopayProjections(
                $budget,
                $startDate,
                $endDate
            );

            return $autopayProjections->map(function ($projection) {
                return [
                    'credit_card' => $projection->description ?? 'Unknown Card',
                    'source_account' => $projection->source_account_name ?? 'Unknown',
                    'payment_date' => Carbon::parse($projection->date ?? now())->format('Y-m-d'),
                    'projected_amount' => abs(($projection->amount_in_cents ?? 0) / 100),
                    'is_first_payment' => $projection->is_first_payment ?? false,
                ];
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate estimated monthly cash flow.
     */
    protected function calculateMonthlyCashFlow(Budget $budget): array
    {
        $totalCashFlow = 0;
        $accountBreakdown = [];

        foreach ($budget->accounts as $account) {
            try {
                $cashFlow = $this->budgetService->calculateMonthlyProjectedCashFlow($account);
                $totalCashFlow += $cashFlow;
                
                if ($cashFlow != 0) {
                    $accountBreakdown[] = [
                        'account' => $account->name,
                        'monthly_cash_flow' => $cashFlow / 100,
                    ];
                }
            } catch (\Exception $e) {
                // Skip accounts that fail
            }
        }

        return [
            'total_monthly_cash_flow' => round($totalCashFlow / 100, 2),
            'is_positive' => $totalCashFlow >= 0,
            'by_account' => $accountBreakdown,
        ];
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'projections';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        // Projections are fairly verbose, estimate ~300 tokens
        return 300;
    }
}
