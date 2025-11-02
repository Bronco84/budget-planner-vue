<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    /**
     * Display the reports page for a budget
     */
    public function index(Budget $budget, Request $request): Response
    {
        $this->authorize('view', $budget);

        // Load relationships
        $budget->load([
            'accounts',
            'categories',
            'recurringTransactionTemplates',
            'payoffPlans'
        ]);

        // Get date range from request or use defaults
        $dateRange = $request->input('date_range', '6months');
        $startDate = $this->getStartDate($dateRange, $request->input('start_date'));
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : now();

        // Get all reports data
        $netWorthData = $this->getNetWorthData($budget, $startDate, $endDate);
        $cashFlowData = $this->getCashFlowData($budget, $startDate, $endDate);
        $budgetPerformanceData = $this->getBudgetPerformanceData($budget, $startDate, $endDate);
        $spendingPatternsData = $this->getSpendingPatternsData($budget, $startDate, $endDate);
        $debtPayoffData = $this->getDebtPayoffData($budget, $startDate, $endDate);

        // Get overview stats
        $overviewStats = $this->getOverviewStats($budget, $netWorthData, $cashFlowData);

        return Inertia::render('Reports/Index', [
            'budget' => $budget,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
                'preset' => $dateRange,
            ],
            'overview' => $overviewStats,
            'netWorth' => $netWorthData,
            'cashFlow' => $cashFlowData,
            'budgetPerformance' => $budgetPerformanceData,
            'spendingPatterns' => $spendingPatternsData,
            'debtPayoff' => $debtPayoffData,
        ]);
    }

    /**
     * Get start date based on preset or custom date
     */
    private function getStartDate(string $dateRange, ?string $customStartDate): Carbon
    {
        if ($dateRange === 'custom' && $customStartDate) {
            return Carbon::parse($customStartDate);
        }

        return match($dateRange) {
            '30days' => now()->subDays(30),
            '3months' => now()->subMonths(3),
            '6months' => now()->subMonths(6),
            'ytd' => now()->startOfYear(),
            'year' => now()->subYear(),
            'all' => Carbon::create(2000, 1, 1), // Far back enough for most budgets
            default => now()->subMonths(6),
        };
    }

    /**
     * Get net worth data (assets - liabilities over time)
     */
    private function getNetWorthData(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        $accounts = $budget->accounts()->with('transactions')->get();

        // Calculate net worth at various points in time
        $dataPoints = [];
        $currentDate = $startDate->copy();

        // Determine interval based on date range
        $daysDiff = $startDate->diffInDays($endDate);
        $interval = match(true) {
            $daysDiff <= 31 => 1, // Daily for 1 month
            $daysDiff <= 90 => 3, // Every 3 days for 3 months
            $daysDiff <= 180 => 7, // Weekly for 6 months
            $daysDiff <= 365 => 15, // Bi-weekly for 1 year
            default => 30, // Monthly for longer periods
        };

        while ($currentDate <= $endDate) {
            $assets = 0;
            $liabilities = 0;

            foreach ($accounts as $account) {
                $balance = $this->calculateBalanceAtDate($account, $currentDate);

                if ($account->isLiability()) {
                    $liabilities += $balance;
                } else {
                    $assets += $balance;
                }
            }

            $dataPoints[] = [
                'date' => $currentDate->format('Y-m-d'),
                'assets' => $assets,
                'liabilities' => $liabilities,
                'netWorth' => $assets - $liabilities,
            ];

            $currentDate->addDays($interval);
        }

        // Add current date if not already included
        if (end($dataPoints)['date'] !== $endDate->format('Y-m-d')) {
            $assets = 0;
            $liabilities = 0;

            foreach ($accounts as $account) {
                $balance = $this->calculateBalanceAtDate($account, $endDate);

                if ($account->isLiability()) {
                    $liabilities += $balance;
                } else {
                    $assets += $balance;
                }
            }

            $dataPoints[] = [
                'date' => $endDate->format('Y-m-d'),
                'assets' => $assets,
                'liabilities' => $liabilities,
                'netWorth' => $assets - $liabilities,
            ];
        }

        return [
            'dataPoints' => $dataPoints,
            'summary' => [
                'currentAssets' => end($dataPoints)['assets'],
                'currentLiabilities' => end($dataPoints)['liabilities'],
                'currentNetWorth' => end($dataPoints)['netWorth'],
                'startingNetWorth' => $dataPoints[0]['netWorth'],
                'change' => end($dataPoints)['netWorth'] - $dataPoints[0]['netWorth'],
                'changePercent' => $dataPoints[0]['netWorth'] != 0
                    ? ((end($dataPoints)['netWorth'] - $dataPoints[0]['netWorth']) / abs($dataPoints[0]['netWorth'])) * 100
                    : 0,
            ],
        ];
    }

    /**
     * Calculate account balance at a specific date
     */
    private function calculateBalanceAtDate($account, Carbon $date): int
    {
        // Get current balance
        $currentBalance = $account->current_balance_cents;

        // Get all transactions after the target date
        $transactionsAfterDate = $account->transactions()
            ->where('date', '>', $date->format('Y-m-d'))
            ->sum('amount_in_cents');

        // Subtract future transactions from current balance
        return $currentBalance - $transactionsAfterDate;
    }

    /**
     * Get cash flow data (income vs expenses over time)
     */
    private function getCashFlowData(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        // Get monthly cash flow
        $transactions = $budget->transactions()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'))
            ->orderBy('date')
            ->get();

        // Group by month
        $monthlyData = [];
        $currentMonth = $startDate->copy()->startOfMonth();

        while ($currentMonth <= $endDate) {
            $monthKey = $currentMonth->format('Y-m');
            $monthTransactions = $transactions->filter(function($transaction) use ($currentMonth) {
                return Carbon::parse($transaction->date)->format('Y-m') === $currentMonth->format('Y-m');
            });

            $income = $monthTransactions->where('amount_in_cents', '>', 0)->sum('amount_in_cents');
            $expenses = abs($monthTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents'));

            $monthlyData[] = [
                'month' => $currentMonth->format('M Y'),
                'income' => $income,
                'expenses' => $expenses,
                'netCashFlow' => $income - $expenses,
            ];

            $currentMonth->addMonth();
        }

        // Calculate averages
        $avgIncome = collect($monthlyData)->avg('income');
        $avgExpenses = collect($monthlyData)->avg('expenses');
        $avgNetCashFlow = collect($monthlyData)->avg('netCashFlow');

        return [
            'monthly' => $monthlyData,
            'summary' => [
                'totalIncome' => collect($monthlyData)->sum('income'),
                'totalExpenses' => collect($monthlyData)->sum('expenses'),
                'netCashFlow' => collect($monthlyData)->sum('netCashFlow'),
                'avgMonthlyIncome' => $avgIncome,
                'avgMonthlyExpenses' => $avgExpenses,
                'avgNetCashFlow' => $avgNetCashFlow,
                'savingsRate' => $avgIncome > 0 ? ($avgNetCashFlow / $avgIncome) * 100 : 0,
            ],
        ];
    }

    /**
     * Get budget performance data (categories vs actual spending)
     */
    private function getBudgetPerformanceData(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        $categories = $budget->categories;
        $transactions = $budget->transactions()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'))
            ->get();

        $categoryPerformance = [];

        foreach ($categories as $category) {
            $categoryTransactions = $transactions->where('category', $category->name);
            $spent = abs($categoryTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents'));
            $allocated = $category->amount * 100; // Convert to cents

            $categoryPerformance[] = [
                'category' => $category->name,
                'allocated' => $allocated,
                'spent' => $spent,
                'remaining' => $allocated - $spent,
                'percentUsed' => $allocated > 0 ? ($spent / $allocated) * 100 : 0,
                'isOverBudget' => $spent > $allocated,
                'color' => $category->color,
            ];
        }

        // Sort by spending (highest first)
        usort($categoryPerformance, fn($a, $b) => $b['spent'] <=> $a['spent']);

        return [
            'categories' => $categoryPerformance,
            'summary' => [
                'totalAllocated' => collect($categoryPerformance)->sum('allocated'),
                'totalSpent' => collect($categoryPerformance)->sum('spent'),
                'totalRemaining' => collect($categoryPerformance)->sum('remaining'),
                'categoriesOverBudget' => collect($categoryPerformance)->where('isOverBudget', true)->count(),
            ],
        ];
    }

    /**
     * Get spending patterns data (by category and merchant)
     */
    private function getSpendingPatternsData(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        $transactions = $budget->transactions()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'))
            ->where('amount_in_cents', '<', 0)
            ->with('plaidTransaction')
            ->get();

        // Group by category
        $byCategory = $transactions->groupBy('category')->map(function($categoryTransactions, $category) {
            return [
                'category' => $category ?: 'Uncategorized',
                'total' => abs($categoryTransactions->sum('amount_in_cents')),
                'count' => $categoryTransactions->count(),
                'average' => abs($categoryTransactions->avg('amount_in_cents')),
            ];
        })->sortByDesc('total')->values()->take(10)->toArray();

        // Group by merchant (from Plaid data)
        $byMerchant = $transactions->filter(function($transaction) {
            return $transaction->plaidTransaction && $transaction->plaidTransaction->merchant_name;
        })->groupBy(function($transaction) {
            return $transaction->plaidTransaction->merchant_name;
        })->map(function($merchantTransactions, $merchant) {
            return [
                'merchant' => $merchant,
                'total' => abs($merchantTransactions->sum('amount_in_cents')),
                'count' => $merchantTransactions->count(),
                'average' => abs($merchantTransactions->avg('amount_in_cents')),
            ];
        })->sortByDesc('total')->values()->take(10)->toArray();

        return [
            'topCategories' => $byCategory,
            'topMerchants' => $byMerchant,
            'totalTransactions' => $transactions->count(),
            'totalSpending' => abs($transactions->sum('amount_in_cents')),
            'averageTransaction' => abs($transactions->avg('amount_in_cents')),
        ];
    }

    /**
     * Get debt payoff data
     */
    private function getDebtPayoffData(Budget $budget, Carbon $startDate, Carbon $endDate): array
    {
        $debtAccounts = $budget->accounts()->where('type', 'like', '%liability%')->get();
        $payoffPlans = $budget->payoffPlans()->with('account')->get();

        $debtSummary = [];

        foreach ($debtAccounts as $account) {
            $startBalance = $this->calculateBalanceAtDate($account, $startDate);
            $currentBalance = $account->current_balance_cents;
            $paidOff = $startBalance - $currentBalance;

            // Find associated payoff plan
            $payoffPlan = $payoffPlans->firstWhere('account_id', $account->id);

            $debtSummary[] = [
                'accountName' => $account->name,
                'accountType' => $account->type,
                'startBalance' => $startBalance,
                'currentBalance' => $currentBalance,
                'paidOff' => $paidOff,
                'percentPaidOff' => $startBalance > 0 ? ($paidOff / $startBalance) * 100 : 0,
                'hasPayoffPlan' => $payoffPlan !== null,
                'payoffPlan' => $payoffPlan ? [
                    'targetDate' => $payoffPlan->target_payoff_date,
                    'monthlyPayment' => $payoffPlan->monthly_payment_cents,
                    'interestRate' => $payoffPlan->interest_rate,
                ] : null,
            ];
        }

        return [
            'debts' => $debtSummary,
            'summary' => [
                'totalDebt' => collect($debtSummary)->sum('currentBalance'),
                'totalPaidOff' => collect($debtSummary)->sum('paidOff'),
                'numberOfDebts' => count($debtSummary),
                'avgPayoffProgress' => count($debtSummary) > 0
                    ? collect($debtSummary)->avg('percentPaidOff')
                    : 0,
            ],
        ];
    }

    /**
     * Get overview statistics
     */
    private function getOverviewStats(Budget $budget, array $netWorthData, array $cashFlowData): array
    {
        return [
            'netWorth' => [
                'current' => $netWorthData['summary']['currentNetWorth'],
                'change' => $netWorthData['summary']['change'],
                'changePercent' => $netWorthData['summary']['changePercent'],
            ],
            'cashFlow' => [
                'avgMonthlyIncome' => $cashFlowData['summary']['avgMonthlyIncome'],
                'avgMonthlyExpenses' => $cashFlowData['summary']['avgMonthlyExpenses'],
                'savingsRate' => $cashFlowData['summary']['savingsRate'],
            ],
            'assets' => $netWorthData['summary']['currentAssets'],
            'liabilities' => $netWorthData['summary']['currentLiabilities'],
        ];
    }
}
