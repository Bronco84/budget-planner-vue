<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PayoffPlanService
{
    protected RecurringTransactionService $recurringTransactionService;

    public function __construct(RecurringTransactionService $recurringTransactionService)
    {
        $this->recurringTransactionService = $recurringTransactionService;
    }

    /**
     * Calculate available monthly cash flow for debt payoff.
     * This uses the projected cash flow across all accounts in the budget,
     * including autopay deductions from credit cards based on statement balances.
     */
    public function calculateAvailableCashFlow(Budget $budget): int
    {
        $totalProjectedCashFlow = 0;

        foreach ($budget->accounts as $account) {
            $accountCashFlow = $this->recurringTransactionService->calculateMonthlyProjectedCashFlow($account);
            $totalProjectedCashFlow += $accountCashFlow;
        }

        // Include autopay projections for credit cards (statement-based payments)
        // These are deductions from checking/savings accounts to pay credit card balances
        $startDate = Carbon::now();
        $endDate = Carbon::now()->addDays(30);
        $autopayProjections = $this->recurringTransactionService->generateAutopayProjections($budget, $startDate, $endDate);

        foreach ($autopayProjections as $projection) {
            // Autopay amounts are already negative (deductions), so add them directly
            $totalProjectedCashFlow += $projection->amount_in_cents;
        }

        return $totalProjectedCashFlow;
    }

    /**
     * Get all debt accounts (liabilities) for a budget.
     */
    public function getDebtAccounts(Budget $budget): Collection
    {
        return $budget->accounts()
            ->get()
            ->filter(fn (Account $account) => $account->isLiability() && $account->current_balance_cents > 0);
    }

    /**
     * Calculate the payoff projection for a given plan.
     * Returns a timeline of payments and balances over time.
     */
    public function calculatePayoffProjection(PayoffPlan $plan): array
    {
        // An unsaved plan (strategy preview) carries its unsaved debts, with
        // their accounts, as a set relation; a saved plan is read from the DB.
        $debts = $plan->exists
            ? $plan->debts()->with('account')->get()
            : $plan->getRelationValue('debts') ?? collect();
        $strategy = $plan->strategy;
        $monthlyExtraPayment = $plan->monthly_extra_payment_cents;

        // Initialize debt data
        $debtData = $debts->map(function (PayoffPlanDebt $debt) {
            return [
                'id' => $debt->id,
                'account_id' => $debt->account_id,
                'name' => $debt->account?->name,
                'balance' => $debt->starting_balance_cents,
                'interest_rate' => $debt->interest_rate,
                'minimum_payment' => $debt->minimum_payment_cents,
                'priority' => $debt->priority,
                'total_paid' => 0,
                'total_interest_paid' => 0,
                'paid_off_month' => $debt->starting_balance_cents > 0 ? null : 0,
                'never_paid_off' => false,
            ];
        })->toArray();

        // Sort debts based on strategy
        $debtData = $this->sortDebtsByStrategy($debtData, $strategy);

        // Total paid toward debt each month stays constant, so minimums freed
        // by paid-off debts roll over to the remaining ones.
        $monthlyPaymentBudget = $monthlyExtraPayment + array_sum(array_column($debtData, 'minimum_payment'));

        // Calculate month-by-month projections
        $timeline = [];
        $currentDate = $plan->start_date->copy();
        $month = 0;
        $maxMonths = 600; // 50 years maximum to prevent infinite loops

        while (count(array_filter($debtData, fn ($d) => $d['balance'] > 0)) > 0 && $month < $maxMonths) {
            $month++;
            $monthData = [
                'month' => $month,
                'date' => $currentDate->format('Y-m-d'),
                'debts' => [],
                'total_balance' => 0,
                'total_payment' => 0,
                'extra_payment_used' => 0,
            ];

            // Apply interest to all debts
            foreach ($debtData as &$debt) {
                if ($debt['balance'] > 0) {
                    $monthlyInterestRate = $debt['interest_rate'] / 100 / 12;
                    $interestCharge = (int) round($debt['balance'] * $monthlyInterestRate);
                    $debt['balance'] += $interestCharge;
                    $debt['total_interest_paid'] += $interestCharge;
                }
            }
            unset($debt);

            // Snowball/avalanche: the monthly budget is every debt's minimum plus
            // the extra payment. Minimums are paid on all open debts first; the
            // remainder (extra + minimums freed by paid-off debts + anything a
            // debt didn't need this month) goes to open debts in priority order.
            $remaining = $monthlyPaymentBudget;

            foreach ($debtData as &$debt) {
                if ($debt['balance'] > 0) {
                    $payment = min($debt['minimum_payment'], $debt['balance'], $remaining);
                    $debt['balance'] -= $payment;
                    $debt['total_paid'] += $payment;
                    $remaining -= $payment;
                    $monthData['total_payment'] += $payment;
                }
            }
            unset($debt);

            foreach ($debtData as &$debt) {
                if ($remaining <= 0) {
                    break;
                }
                if ($debt['balance'] > 0) {
                    $extraPayment = min($remaining, $debt['balance']);
                    $debt['balance'] -= $extraPayment;
                    $debt['total_paid'] += $extraPayment;
                    $remaining -= $extraPayment;
                    $monthData['extra_payment_used'] += $extraPayment;
                    $monthData['total_payment'] += $extraPayment;
                }
            }
            unset($debt);

            foreach ($debtData as &$debt) {
                if ($debt['balance'] <= 0 && $debt['paid_off_month'] === null) {
                    $debt['paid_off_month'] = $month;
                }
            }
            unset($debt);

            // Record debt states for this month
            foreach ($debtData as $debt) {
                $monthData['debts'][] = [
                    'id' => $debt['id'],
                    'name' => $debt['name'],
                    'balance' => $debt['balance'],
                    'total_paid' => $debt['total_paid'],
                    'total_interest_paid' => $debt['total_interest_paid'],
                ];
                $monthData['total_balance'] += $debt['balance'];
            }

            $timeline[] = $monthData;
            $currentDate->addMonth();
        }

        // Payments that can't outpace interest stop at the month cap with
        // balances still owed; report that explicitly instead of a far-future date.
        foreach ($debtData as &$debt) {
            $debt['never_paid_off'] = $debt['balance'] > 0;
        }
        unset($debt);
        $neverPaidOff = count(array_filter($debtData, fn ($d) => $d['never_paid_off'])) > 0;

        return [
            'timeline' => $timeline,
            'total_months' => $month,
            'never_paid_off' => $neverPaidOff,
            'payoff_date' => $neverPaidOff ? null : $currentDate->format('Y-m-d'),
            'total_interest_paid' => array_sum(array_column($debtData, 'total_interest_paid')),
            'final_debt_data' => $debtData,
        ];
    }

    /**
     * Sort debts based on the selected strategy.
     */
    protected function sortDebtsByStrategy(array $debts, string $strategy): array
    {
        switch ($strategy) {
            case 'snowball':
                // Sort by balance (smallest first)
                usort($debts, fn ($a, $b) => $a['balance'] <=> $b['balance']);
                break;

            case 'avalanche':
                // Sort by interest rate (highest first)
                usort($debts, fn ($a, $b) => $b['interest_rate'] <=> $a['interest_rate']);
                break;

            case 'custom':
                // Sort by user-defined priority
                usort($debts, fn ($a, $b) => $a['priority'] <=> $b['priority']);
                break;
        }

        return $debts;
    }

    /**
     * Compare different payoff strategies for a budget.
     *
     * Runs entirely in memory: each strategy gets an unsaved PayoffPlan whose
     * unsaved debts are passed to calculatePayoffProjection(), so a preview
     * never writes rows.
     */
    public function compareStrategies(Budget $budget, array $debtData, int $monthlyExtraPayment): array
    {
        $accounts = Account::where('budget_id', $budget->id)
            ->whereIn('id', array_column($debtData, 'account_id'))
            ->get()
            ->keyBy('id');

        $comparisons = [];

        foreach (['snowball', 'avalanche'] as $strategy) {
            $plan = new PayoffPlan([
                'name' => "Strategy comparison ({$strategy})",
                'strategy' => $strategy,
                'monthly_extra_payment_cents' => $monthlyExtraPayment,
                'start_date' => Carbon::now(),
            ]);
            $plan->budget_id = $budget->id;

            $plan->setRelation('debts', collect($debtData)->map(function (array $debt) use ($accounts) {
                $planDebt = new PayoffPlanDebt([
                    'account_id' => $debt['account_id'],
                    'starting_balance_cents' => $debt['balance'],
                    'interest_rate' => $debt['interest_rate'],
                    'minimum_payment_cents' => $debt['minimum_payment'],
                    'priority' => $debt['priority'] ?? 0,
                ]);

                return $planDebt->setRelation('account', $accounts->get($debt['account_id']));
            }));

            $projection = $this->calculatePayoffProjection($plan);

            $comparisons[$strategy] = [
                'strategy' => $strategy,
                'total_months' => $projection['total_months'],
                'payoff_date' => $projection['payoff_date'],
                'never_paid_off' => $projection['never_paid_off'],
                'total_interest_paid' => $projection['total_interest_paid'],
            ];
        }

        return $comparisons;
    }

    /**
     * Calculate goal projections based on monthly contributions.
     */
    public function calculateGoalProjections(PayoffPlan $plan): array
    {
        $goals = $plan->goals;
        $projections = [];

        foreach ($goals as $goal) {
            $monthlyContribution = $goal->monthly_contribution_cents;
            $targetAmount = $goal->target_amount_cents;

            if ($monthlyContribution <= 0) {
                $projections[] = [
                    'goal_id' => $goal->id,
                    'name' => $goal->name,
                    'months_to_complete' => null,
                    'completion_date' => null,
                ];

                continue;
            }

            $monthsToComplete = (int) ceil($targetAmount / $monthlyContribution);
            $completionDate = $plan->start_date->copy()->addMonths($monthsToComplete);

            $projections[] = [
                'goal_id' => $goal->id,
                'name' => $goal->name,
                'target_amount' => $targetAmount,
                'monthly_contribution' => $monthlyContribution,
                'months_to_complete' => $monthsToComplete,
                'completion_date' => $completionDate->format('Y-m-d'),
                'target_date' => $goal->target_date?->format('Y-m-d'),
            ];
        }

        return $projections;
    }
}
