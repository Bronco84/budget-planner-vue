<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
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
        $initialBalance = $account->current_balance_cents;
        $balanceProjection = $this->projectDailyBalance($projectedTransactions, $initialBalance, $startDate, $monthsAhead);
        
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
     * @param \Illuminate\Support\Collection $projectedTransactions
     * @param int $initialBalance
     * @param Carbon $startDate
     * @param int $monthsAhead
     * @return array
     */
    protected function projectDailyBalance($projectedTransactions, $initialBalance, $startDate, $monthsAhead)
    {
        $endDate = $startDate->copy()->addMonths($monthsAhead);
        $dailyProjection = [];
        $runningBalance = $initialBalance;
        
        // Group transactions by date
        $transactionsByDate = $projectedTransactions->groupBy(function ($transaction) {
            return Carbon::parse($transaction['date'])->format('Y-m-d');
        });
        
        // Generate a date range from start to end
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dateKey = $currentDate->format('Y-m-d');
            
            // Apply any transactions for this date
            if ($transactionsByDate->has($dateKey)) {
                foreach ($transactionsByDate->get($dateKey) as $transaction) {
                    $runningBalance += $transaction['amount_in_cents'];
                }
            }
            
            // Add to the projection
            $dailyProjection[] = [
                'date' => $dateKey,
                'balance' => $runningBalance,
                'formatted_balance' => '$' . number_format($runningBalance / 100, 2),
            ];
            
            $currentDate->addDay();
        }
        
        // Wrap the daily projection data in an object with a 'days' property to match frontend expectations
        return ['days' => $dailyProjection];
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
        $initialBalance = $account->current_balance_cents;
        $balanceProjection = $this->projectDailyBalance($projectedTransactions, $initialBalance, $startDate, $monthsAhead);
        
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
}
