<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Scenario;
use App\Services\RecurringTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Collection;

class ProjectionsController extends Controller
{
    /**
     * The recurring transaction service.
     *
     * @var RecurringTransactionService
     */
    protected $recurringTransactionService;

    /**
     * Create a new controller instance.
     *
     * @param RecurringTransactionService $recurringTransactionService
     */
    public function __construct(RecurringTransactionService $recurringTransactionService)
    {
        $this->recurringTransactionService = $recurringTransactionService;
    }

    /**
     * Show the budget projections page.
     *
     * @param Budget $budget
     * @param Request $request
     * @return \Inertia\Response
     */
    public function showBudgetProjections(Budget $budget, Request $request)
    {
        $monthsAhead = $request->input('months', 3);
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();
        
        // Get all accounts for this budget
        $accounts = $budget->accounts;
        $projectedTransactions = collect();
        
        // Project transactions for each account
        foreach ($accounts as $account) {
            $accountProjections = $this->recurringTransactionService->projectTransactions(
                $account, 
                $startDate, 
                $endDate
            );
            
            $projectedTransactions = $projectedTransactions->merge($accountProjections);
        }
        
        // Group by month
        $projectionsByMonth = collect();
        foreach ($projectedTransactions as $transaction) {
            $date = Carbon::parse($transaction['date']);
            $monthKey = $date->format('Y-m');
            
            if (!$projectionsByMonth->has($monthKey)) {
                $projectionsByMonth->put($monthKey, [
                    'month' => $date->format('F Y'),
                    'month_key' => $monthKey,
                    'transactions' => collect(),
                    'income' => 0,
                    'expenses' => 0,
                    'net' => 0,
                ]);
            }
            
            $monthData = $projectionsByMonth->get($monthKey);
            $monthData['transactions']->push($transaction);
            
            // Update totals
            if ($transaction['amount_in_cents'] > 0) {
                $monthData['income'] += $transaction['amount_in_cents'];
            } else {
                $monthData['expenses'] += abs($transaction['amount_in_cents']);
            }
            $monthData['net'] += $transaction['amount_in_cents'];
            
            $projectionsByMonth->put($monthKey, $monthData);
        }
        
        return Inertia::render('Budgets/Projections', [
            'budget' => $budget,
            'projections' => $projectionsByMonth->values(),
            'monthsAhead' => $monthsAhead,
        ]);
    }
    
    /**
     * Show the account projections page.
     *
     * @param Budget $budget
     * @param Account $account
     * @param Request $request
     * @return \Inertia\Response
     */
    public function showAccountProjections(Budget $budget, Account $account, Request $request)
    {
        $monthsAhead = $request->input('months', 3);
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();
        
        // Get projected transactions for this account
        $projectedTransactions = $this->recurringTransactionService->projectTransactions(
            $account,
            $startDate,
            $endDate
        );
        
        // Ensure projectedTransactions is always a collection
        if (!$projectedTransactions instanceof Collection) {
            $projectedTransactions = collect($projectedTransactions);
        }
        
        // Project daily balance for the account
        // Use different calculation for liabilities vs assets
        $initialBalance = $account->current_balance_cents;
        $balanceProjection = $this->projectDailyBalance(
            $projectedTransactions, 
            $initialBalance, 
            $startDate, 
            $monthsAhead,
            $account->isLiability()
        );
        
        // Ensure balanceProjection has the expected structure
        if (!isset($balanceProjection['days'])) {
            $balanceProjection = ['days' => []];
        }
        
        return Inertia::render('Accounts/Projections', [
            'budget' => $budget,
            'account' => $account,
            'projectedTransactions' => $projectedTransactions,
            'balanceProjection' => $balanceProjection,
            'monthsAhead' => $monthsAhead,
        ]);
    }
    
    /**
     * Project daily account balance over time based on projected transactions.
     *
     * For asset accounts: balance += transaction amount
     * For liability accounts: balance -= transaction amount
     *
     * @param \Illuminate\Support\Collection $projectedTransactions
     * @param int $initialBalance
     * @param Carbon $startDate
     * @param int $monthsAhead
     * @param bool $isLiability Whether this is a liability account (credit card, loan, etc.)
     * @return array
     */
    protected function projectDailyBalance($projectedTransactions, $initialBalance, $startDate, $monthsAhead, bool $isLiability = false)
    {
        $endDate = $startDate->copy()->addMonths($monthsAhead);
        $weeklyProjection = [];
        $runningBalance = $initialBalance;
        
        // Group transactions by date
        $transactionsByDate = $projectedTransactions->groupBy(function ($transaction) {
            return Carbon::parse($transaction['date'])->format('Y-m-d');
        });
        
        // Generate weekly data points
        $currentDate = $startDate->copy()->startOfWeek(); // Start at beginning of week
        
        while ($currentDate->lte($endDate)) {
            $weekIncome = 0;
            $weekExpense = 0;
            
            // Process all transactions for the next 7 days
            $weekStart = $currentDate->copy();
            $weekEnd = $currentDate->copy()->addDays(6);
            
            $processDate = $weekStart->copy();
            while ($processDate->lte($weekEnd) && $processDate->lte($endDate)) {
                $dateKey = $processDate->format('Y-m-d');
                
                // Apply any transactions for this date
                if ($transactionsByDate->has($dateKey)) {
                    foreach ($transactionsByDate->get($dateKey) as $transaction) {
                        $amount = $transaction['amount_in_cents'];
                        
                        // For liabilities, subtract (spending increases debt, payments decrease debt)
                        // For assets, add (spending decreases balance, deposits increase balance)
                        if ($isLiability) {
                            $runningBalance -= $amount;
                        } else {
                            $runningBalance += $amount;
                        }
                        
                        // Track income and expenses separately
                        if ($amount > 0) {
                            $weekIncome += $amount;
                        } else {
                            $weekExpense += abs($amount);
                        }
                    }
                }
                
                $processDate->addDay();
            }
            
            // Add weekly data point (use end of week as the date)
            $weeklyProjection[] = [
                'date' => $weekEnd->format('Y-m-d'),
                'balance' => $runningBalance,
                'income' => $weekIncome,
                'expense' => $weekExpense
            ];
            
            // Move to next week
            $currentDate->addWeek();
        }
        
        // Wrap the weekly projection data in an object with a 'days' property to match frontend expectations
        return ['days' => $weeklyProjection];
    }

    /**
     * Show the detailed balance projection page for an account.
     *
     * @param Budget $budget
     * @param Account $account
     * @param Request $request
     * @return \Inertia\Response
     */
    public function showBalanceProjection(Budget $budget, Account $account, Request $request)
    {
        // Ensure account belongs to budget
        if ($account->budget_id !== $budget->id) {
            abort(404, 'Account not found in this budget');
        }
        
        $monthsAhead = intval($request->input('months', 12));
        $scenario = $request->input('scenario', 'default');
        $groupBy = $request->input('groupBy', 'day');
        
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();
        
        // Get projected transactions for this account
        $projectedTransactions = $this->recurringTransactionService->projectTransactions(
            $account,
            $startDate,
            $endDate
        );
        
        // Ensure projectedTransactions is always a collection
        if (!$projectedTransactions instanceof Collection) {
            $projectedTransactions = collect($projectedTransactions);
        }
        
        // Project daily balance for the account
        // Use different calculation for liabilities vs assets
        $initialBalance = $account->current_balance_cents;
        $balanceProjection = $this->projectDailyBalance(
            $projectedTransactions, 
            $initialBalance, 
            $startDate, 
            $monthsAhead,
            $account->isLiability()
        );
        
        // Ensure balanceProjection has the expected structure
        if (!isset($balanceProjection['days'])) {
            $balanceProjection = ['days' => []];
        }
        
        return Inertia::render('Accounts/BalanceProjection', [
            'budget' => $budget,
            'account' => $account,
            'projectedTransactions' => $projectedTransactions,
            'balanceProjection' => $balanceProjection,
            'monthsAhead' => $monthsAhead,
        ]);
    }

    /**
     * Show the multi-account projection page with scenarios.
     *
     * @param Budget $budget
     * @param Request $request
     * @return \Inertia\Response
     */
    public function showMultiAccountProjection(Budget $budget, Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'exists:accounts,id',
            'scenario_ids' => 'nullable|array',
            'scenario_ids.*' => 'exists:scenarios,id',
            'months' => 'nullable|integer|min:1|max:60',
        ]);

        $accountIds = $validated['account_ids'] ?? [];
        $scenarioIds = $validated['scenario_ids'] ?? [];
        $monthsAhead = intval($validated['months'] ?? 12);

        // If no accounts specified, use all budget accounts
        if (empty($accountIds)) {
            $accounts = $budget->accounts;
        } else {
            // Validate that all accounts belong to this budget
            $accounts = $budget->accounts()->whereIn('id', $accountIds)->get();
            if ($accounts->count() !== count($accountIds)) {
                abort(422, 'One or more accounts do not belong to this budget');
            }
        }

        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();

        // Get all scenarios for this budget
        $allScenarios = $budget->scenarios()->with('adjustments.account')->get();

        // Get active scenarios (either from request or from database)
        if (!empty($scenarioIds)) {
            $activeScenarios = $allScenarios->whereIn('id', $scenarioIds);
        } else {
            $activeScenarios = $allScenarios->where('is_active', true);
        }

        // Calculate base projections for each account
        $baseProjections = [];
        foreach ($accounts as $account) {
            $projectedTransactions = $this->recurringTransactionService->projectTransactions(
                $account,
                $startDate,
                $endDate
            );

            if (!$projectedTransactions instanceof Collection) {
                $projectedTransactions = collect($projectedTransactions);
            }

            $balanceProjection = $this->projectDailyBalance(
                $projectedTransactions,
                $account->current_balance_cents,
                $startDate,
                $monthsAhead,
                $account->isLiability()
            );

            $baseProjections[$account->id] = $balanceProjection;
        }

        // Calculate scenario projections
        $scenarioProjections = [];
        foreach ($activeScenarios as $scenario) {
            $scenarioProjections[$scenario->id] = [];

            foreach ($accounts as $account) {
                // Get base projected transactions
                $projectedTransactions = $this->recurringTransactionService->projectTransactions(
                    $account,
                    $startDate,
                    $endDate
                );

                if (!$projectedTransactions instanceof Collection) {
                    $projectedTransactions = collect($projectedTransactions);
                }

                // Apply scenario adjustments
                $adjustedTransactions = $this->applyScenarioAdjustments(
                    $projectedTransactions->toArray(),
                    $scenario,
                    $account,
                    $startDate,
                    $endDate
                );

                // Calculate balance projection with scenario
                $scenarioBalanceProjection = $this->projectDailyBalance(
                    collect($adjustedTransactions),
                    $account->current_balance_cents,
                    $startDate,
                    $monthsAhead,
                    $account->isLiability()
                );

                $scenarioProjections[$scenario->id][$account->id] = $scenarioBalanceProjection;
            }
        }

        return Inertia::render('Budgets/MultiAccountProjection', [
            'budget' => $budget,
            'accounts' => $accounts,
            'baseProjections' => $baseProjections,
            'scenarioProjections' => $scenarioProjections,
            'scenarios' => $allScenarios,
            'activeScenarios' => $activeScenarios->values(),
            'monthsAhead' => $monthsAhead,
            'breadcrumbs' => \Breadcrumbs::generate('budget.projections.multi-account', $budget),
        ]);
    }

    /**
     * Apply scenario adjustments to projected transactions.
     *
     * @param array $baseTransactions
     * @param Scenario $scenario
     * @param Account $account
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    protected function applyScenarioAdjustments(
        array $baseTransactions,
        Scenario $scenario,
        Account $account,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        // Get adjustments for this account
        $adjustments = $scenario->adjustments()
            ->where('account_id', $account->id)
            ->get();

        if ($adjustments->isEmpty()) {
            return $baseTransactions;
        }

        // Generate additional transactions from adjustments
        $additionalTransactions = [];
        foreach ($adjustments as $adjustment) {
            $adjustmentTransactions = $adjustment->generateProjectedTransactions($startDate, $endDate);
            $additionalTransactions = array_merge($additionalTransactions, $adjustmentTransactions);
        }

        // Merge with base transactions
        $allTransactions = array_merge($baseTransactions, $additionalTransactions);

        // Sort by date
        usort($allTransactions, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $allTransactions;
    }
}
