<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\PayoffPlanGoal;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PayoffPlanService
{
    /**
     * Projection cap (50 years) so payments that never outpace interest stop.
     */
    protected const MAX_MONTHS = 600;

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
     * Get all debt accounts (liabilities carrying a balance) for a budget.
     *
     * Liability balances are debt whatever their stored sign (cards synced
     * as negative balances included), matching how net worth and projections
     * read them with abs().
     */
    public function getDebtAccounts(Budget $budget): Collection
    {
        return $budget->accounts()
            ->get()
            ->filter(fn (Account $account) => $account->isLiability() && $account->current_balance_cents != 0)
            ->values();
    }

    /**
     * The amount owed on a debt account, as a positive number of cents.
     */
    public static function debtBalanceCents(Account $account): int
    {
        return abs((int) $account->current_balance_cents);
    }

    /**
     * The date a plan's projection starts: the plan's start date, or today
     * once that date has passed (balances are live, so the projection runs
     * forward from now rather than replaying months already behind us).
     */
    public function projectionStartDate(PayoffPlan $plan): Carbon
    {
        $today = Carbon::today();

        return $plan->start_date->greaterThan($today) ? $plan->start_date->copy() : $today;
    }

    /**
     * Calculate the payoff projection for a given plan.
     * Returns a timeline of payments and balances over time.
     *
     * Each debt starts from its account's current (live) balance; the stored
     * starting_balance_cents is the balance when the plan was created and is
     * only used to report progress. Month 1 applies payments before any
     * interest, so a plan doesn't open with a full month of interest; from
     * month 2 on, interest accrues on the running balance before that month's
     * payment.
     */
    public function calculatePayoffProjection(PayoffPlan $plan): array
    {
        $debts = $plan->debts()->with('account')->get();
        $strategy = $plan->strategy;
        $monthlyExtraPayment = $plan->monthly_extra_payment_cents;
        $startDate = $this->projectionStartDate($plan);

        // Initialize debt data
        $debtData = $debts->map(function (PayoffPlanDebt $debt) {
            $currentBalance = $debt->current_balance_cents;

            return [
                'id' => $debt->id,
                'account_id' => $debt->account_id,
                'name' => $debt->account?->name,
                'balance' => $currentBalance,
                'starting_balance' => $debt->starting_balance_cents,
                'current_balance' => $currentBalance,
                'paid_down_since_start' => $debt->starting_balance_cents - $currentBalance,
                'interest_rate' => $debt->interest_rate,
                'minimum_payment' => $debt->minimum_payment_cents,
                'priority' => $debt->priority,
                'total_paid' => 0,
                'total_interest_paid' => 0,
                'paid_off_month' => $currentBalance > 0 ? null : 0,
                'never_paid_off' => false,
            ];
        })->toArray();

        // Sort debts based on strategy
        $debtData = $this->sortDebtsByStrategy($debtData, $strategy);

        // Total paid toward debt each month stays constant, so minimums freed
        // by paid-off debts roll over to the remaining ones.
        $monthlyPaymentBudget = $this->monthlyPaymentBudget($plan, $debtData);

        // Calculate month-by-month projections
        $timeline = [];
        $month = 0;

        while (count(array_filter($debtData, fn ($d) => $d['balance'] > 0)) > 0 && $month < self::MAX_MONTHS) {
            $month++;
            $monthData = [
                'month' => $month,
                'date' => $startDate->copy()->addMonthsNoOverflow($month - 1)->format('Y-m-d'),
                'debts' => [],
                'total_balance' => 0,
                'total_payment' => 0,
                'extra_payment_used' => 0,
            ];

            // Apply interest to all debts. Month 1 skips this: its payment lands
            // first, and the interest on what remains is charged in month 2.
            if ($month > 1) {
                foreach ($debtData as &$debt) {
                    if ($debt['balance'] > 0) {
                        $monthlyInterestRate = $debt['interest_rate'] / 100 / 12;
                        $interestCharge = (int) round($debt['balance'] * $monthlyInterestRate);
                        $debt['balance'] += $interestCharge;
                        $debt['total_interest_paid'] += $interestCharge;
                    }
                }
                unset($debt);
            }
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
            'projection_start_date' => $startDate->format('Y-m-d'),
            'total_months' => $month,
            'monthly_payment_budget' => $monthlyPaymentBudget,
            'never_paid_off' => $neverPaidOff,
            'payoff_date' => $neverPaidOff ? null : $startDate->copy()->addMonthsNoOverflow($month)->format('Y-m-d'),
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
     * Every debt's minimum payment plus the plan's monthly extra payment: what
     * the plan pays toward debt each month, and what is freed for goals once
     * the debts are paid off.
     */
    protected function monthlyPaymentBudget(PayoffPlan $plan, array $debtData): int
    {
        return (int) $plan->monthly_extra_payment_cents + array_sum(array_column($debtData, 'minimum_payment'));
    }

    /**
     * Calculate goal projections.
     *
     * Goal contributions start the month after every debt is paid off. From
     * then on each month's goal budget is the goals' own monthly contributions
     * plus the freed debt payments (all minimums + the extra payment): every
     * unfinished goal first receives its own contribution, and the rest goes
     * to unfinished goals in priority order (lowest priority number first,
     * then creation order), so finished goals free their share as well. While
     * the debts are never paid off, goals receive nothing.
     *
     * months_to_complete counts from the projection start, like total_months.
     */
    public function calculateGoalProjections(PayoffPlan $plan, ?array $debtProjection = null): array
    {
        $debtProjection ??= $this->calculatePayoffProjection($plan);
        $startDate = Carbon::parse($debtProjection['projection_start_date']);

        $goals = $plan->goals()->orderBy('priority')->orderBy('id')->get();

        $goalData = $goals->map(fn (PayoffPlanGoal $goal) => [
            'goal' => $goal,
            'saved' => 0,
            'completed_month' => $goal->target_amount_cents <= 0 ? 0 : null,
        ])->all();

        $debtsPaidOff = ! $debtProjection['never_paid_off'];
        $startMonth = $debtsPaidOff ? $debtProjection['total_months'] + 1 : null;
        $freedPayments = $debtsPaidOff ? (int) $debtProjection['monthly_payment_budget'] : 0;
        $monthlyGoalBudget = $freedPayments + (int) $goals->sum('monthly_contribution_cents');

        if ($debtsPaidOff && $monthlyGoalBudget > 0) {
            $unfinished = fn () => array_filter($goalData, fn ($g) => $g['completed_month'] === null);

            for ($month = $startMonth; $month <= self::MAX_MONTHS && count($unfinished()) > 0; $month++) {
                $remaining = $monthlyGoalBudget;

                foreach ($goalData as &$data) {
                    if ($data['completed_month'] === null) {
                        $needed = $data['goal']->target_amount_cents - $data['saved'];
                        $contribution = min($data['goal']->monthly_contribution_cents, $needed, $remaining);
                        $data['saved'] += $contribution;
                        $remaining -= $contribution;
                    }
                }
                unset($data);

                foreach ($goalData as &$data) {
                    if ($remaining <= 0) {
                        break;
                    }
                    if ($data['completed_month'] === null) {
                        $contribution = min($remaining, $data['goal']->target_amount_cents - $data['saved']);
                        $data['saved'] += $contribution;
                        $remaining -= $contribution;
                    }
                }
                unset($data);

                foreach ($goalData as &$data) {
                    if ($data['completed_month'] === null && $data['saved'] >= $data['goal']->target_amount_cents) {
                        $data['completed_month'] = $month;
                    }
                }
                unset($data);
            }
        }

        return array_map(function (array $data) use ($startDate, $startMonth) {
            /** @var PayoffPlanGoal $goal */
            $goal = $data['goal'];
            $months = $data['completed_month'];

            return [
                'goal_id' => $goal->id,
                'name' => $goal->name,
                'priority' => $goal->priority,
                'target_amount' => $goal->target_amount_cents,
                'monthly_contribution' => $goal->monthly_contribution_cents,
                'contributions_start_month' => $startMonth,
                'contributions_start_date' => $startMonth === null
                    ? null
                    : $startDate->copy()->addMonthsNoOverflow($startMonth - 1)->format('Y-m-d'),
                'months_to_complete' => $months,
                'completion_date' => $months === null ? null : $startDate->copy()->addMonthsNoOverflow($months)->format('Y-m-d'),
                'target_date' => $goal->target_date?->format('Y-m-d'),
            ];
        }, $goalData);
    }
}
