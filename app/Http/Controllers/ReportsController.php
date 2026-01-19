<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlanDebt;
use App\Models\RecurringTransactionTemplate;
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

        // Get selected account ID from request (null = all accounts)
        $selectedAccountId = $request->input('account_id');
        $selectedAccount = null;
        
        if ($selectedAccountId) {
            $selectedAccount = $budget->accounts()->find($selectedAccountId);
            if (!$selectedAccount) {
                // Invalid account ID, reset to all accounts
                $selectedAccountId = null;
            }
        }

        // Get date range from request or use defaults
        $dateRange = $request->input('date_range', '6months');
        $startDate = $this->getStartDate($dateRange, $request->input('start_date'));
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : now();

        // Get all reports data (pass selected account)
        $netWorthData = $this->getNetWorthData($budget, $startDate, $endDate, $selectedAccount);
        $cashFlowData = $this->getCashFlowData($budget, $startDate, $endDate, $selectedAccount);
        $budgetPerformanceData = $this->getBudgetPerformanceData($budget, $startDate, $endDate, $selectedAccount);
        $spendingPatternsData = $this->getSpendingPatternsData($budget, $startDate, $endDate, $selectedAccount);
        $debtPayoffData = $this->getDebtPayoffData($budget, $startDate, $endDate, $selectedAccount);
        $incomeVsExpensesData = $this->getIncomeVsExpensesData($budget);

        // Get overview stats
        $overviewStats = $this->getOverviewStats($budget, $netWorthData, $cashFlowData);

        return Inertia::render('Reports/Index', [
            'budget' => $budget,
            'accounts' => $budget->accounts->map(fn($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
            ]),
            'selectedAccountId' => $selectedAccountId,
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
            'incomeVsExpenses' => $incomeVsExpensesData,
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
    private function getNetWorthData(Budget $budget, Carbon $startDate, Carbon $endDate, ?Account $selectedAccount = null): array
    {
        // If a specific account is selected, only use that account
        $accounts = $selectedAccount 
            ? collect([$selectedAccount->load('transactions')])
            : $budget->accounts()->with('transactions')->get();

        // Calculate net worth at various points in time
        $dataPoints = [];
        $currentDate = $startDate->copy();

        // Determine interval based on date range
        $daysDiff = (int) $startDate->diffInDays($endDate);
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
                    $liabilities += abs($balance);
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
                    $liabilities += abs($balance);
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
    private function getCashFlowData(Budget $budget, Carbon $startDate, Carbon $endDate, ?Account $selectedAccount = null): array
    {
        // Get monthly cash flow
        $transactionsQuery = $budget->transactions()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'));
        
        // Filter by account if selected
        if ($selectedAccount) {
            $transactionsQuery->where('account_id', $selectedAccount->id);
        }
        
        $transactions = $transactionsQuery->orderBy('date')->get();

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
    private function getBudgetPerformanceData(Budget $budget, Carbon $startDate, Carbon $endDate, ?Account $selectedAccount = null): array
    {
        $categories = $budget->categories;
        $transactionsQuery = $budget->transactions()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'));
        
        // Filter by account if selected
        if ($selectedAccount) {
            $transactionsQuery->where('account_id', $selectedAccount->id);
        }
        
        $transactions = $transactionsQuery->get();

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
    private function getSpendingPatternsData(Budget $budget, Carbon $startDate, Carbon $endDate, ?Account $selectedAccount = null): array
    {
        $transactionsQuery = $budget->transactions()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'))
            ->where('amount_in_cents', '<', 0)
            ->with('plaidTransaction');
        
        // Filter by account if selected
        if ($selectedAccount) {
            $transactionsQuery->where('account_id', $selectedAccount->id);
        }
        
        $transactions = $transactionsQuery->get();

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
    private function getDebtPayoffData(Budget $budget, Carbon $startDate, Carbon $endDate, ?Account $selectedAccount = null): array
    {
        // Get all PayoffPlanDebts for ACTIVE plans in this budget, with the related PayoffPlan and Account
        $payoffPlanDebtsQuery = PayoffPlanDebt::whereHas('payoffPlan', function($query) use ($budget) {
            $query->where('budget_id', $budget->id)
                  ->where('is_active', true);
        })->with(['payoffPlan', 'account']);
        
        // Filter by account if selected
        if ($selectedAccount) {
            $payoffPlanDebtsQuery->where('account_id', $selectedAccount->id);
        }
        
        $payoffPlanDebts = $payoffPlanDebtsQuery->get();

        $debtSummary = [];

        // Only loop through accounts that have active payoff plans
        foreach ($payoffPlanDebts as $payoffPlanDebt) {
            $account = $payoffPlanDebt->account;

            $startBalance = $this->calculateBalanceAtDate($account, $startDate);
            $currentBalance = $account->current_balance_cents;
            $paidOff = $startBalance - $currentBalance;

            $debtSummary[] = [
                'accountName' => $account->name,
                'accountType' => $account->type,
                'startBalance' => $startBalance,
                'currentBalance' => $currentBalance,
                'paidOff' => $paidOff,
                'percentPaidOff' => $startBalance > 0 ? ($paidOff / $startBalance) * 100 : 0,
                'hasPayoffPlan' => true, // Always true since we're only getting accounts with active plans
                'payoffPlan' => [
                    'targetDate' => null, // TODO: Calculate estimated payoff date based on payment schedule
                    'monthlyPayment' => $payoffPlanDebt->minimum_payment_cents,
                    'interestRate' => $payoffPlanDebt->interest_rate,
                ],
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

    /**
     * Get income vs expenses data (recurring transactions and autopay)
     */
    private function getIncomeVsExpensesData(Budget $budget): array
    {
        // Get all accounts for the filter dropdown
        $accounts = $budget->accounts()->select('id', 'name')->get();

        // Get recurring income (positive amounts)
        $incomeTemplates = $budget->recurringTransactionTemplates()
            ->where('amount_in_cents', '>', 0)
            ->with('account')
            ->get()
            ->map(function ($template) {
                return [
                    'id' => $template->id,
                    'account_id' => $template->account_id,
                    'description' => $template->friendly_label ?: $template->description,
                    'amount_in_cents' => $template->amount_in_cents,
                    'frequency' => $template->frequency,
                    'monthly_amount' => $this->calculateMonthlyAmountForTemplate($template),
                    'account_name' => $template->account?->name,
                    'category' => $template->category,
                ];
            });

        // Get recurring expenses (negative amounts)
        $expenseTemplates = $budget->recurringTransactionTemplates()
            ->where('amount_in_cents', '<', 0)
            ->with('account')
            ->get()
            ->map(function ($template) {
                return [
                    'id' => $template->id,
                    'account_id' => $template->account_id,
                    'description' => $template->friendly_label ?: $template->description,
                    'amount_in_cents' => $template->amount_in_cents,
                    'frequency' => $template->frequency,
                    'monthly_amount' => $this->calculateMonthlyAmountForTemplate($template),
                    'account_name' => $template->account?->name,
                    'category' => $template->category,
                ];
            });

        // Get credit cards with autopay enabled
        $autopayAccounts = $budget->accounts()
            ->where('autopay_enabled', true)
            ->whereNotNull('autopay_source_account_id')
            ->with(['plaidAccount', 'autopaySourceAccount'])
            ->get()
            ->filter(fn($account) => $account->hasActiveAutopay())
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'source_account_id' => $account->autopay_source_account_id,
                    'name' => $account->name,
                    'source_account_name' => $account->autopaySourceAccount?->name,
                    'amount_in_cents' => -abs($account->getAutopayAmountCents() ?? 0),
                    'monthly_amount' => -abs($account->getAutopayAmountCents() ?? 0),
                ];
            })
            ->values();

        // Calculate totals
        $totalMonthlyIncome = $incomeTemplates->sum('monthly_amount');
        $totalMonthlyExpenses = abs($expenseTemplates->sum('monthly_amount'));
        $totalMonthlyAutopay = abs($autopayAccounts->sum('monthly_amount'));

        return [
            'accounts' => $accounts,
            'incomeItems' => $incomeTemplates,
            'expenseItems' => $expenseTemplates,
            'autopayItems' => $autopayAccounts,
            'totals' => [
                'monthly_income' => $totalMonthlyIncome,
                'monthly_expenses' => $totalMonthlyExpenses,
                'monthly_autopay' => $totalMonthlyAutopay,
                'net' => $totalMonthlyIncome - $totalMonthlyExpenses - $totalMonthlyAutopay,
            ],
        ];
    }

    /**
     * Calculate monthly amount for a recurring transaction template
     */
    private function calculateMonthlyAmountForTemplate(RecurringTransactionTemplate $template): int
    {
        $amount = $template->amount_in_cents;

        return match ($template->frequency) {
            RecurringTransactionTemplate::FREQUENCY_DAILY => (int) round($amount * 30.44),
            RecurringTransactionTemplate::FREQUENCY_WEEKLY => (int) round($amount * 4.33),
            RecurringTransactionTemplate::FREQUENCY_BIWEEKLY => (int) round($amount * 2.17),
            RecurringTransactionTemplate::FREQUENCY_MONTHLY => $amount,
            RecurringTransactionTemplate::FREQUENCY_BIMONTHLY => $amount * 2,
            RecurringTransactionTemplate::FREQUENCY_QUARTERLY => (int) round($amount / 3),
            RecurringTransactionTemplate::FREQUENCY_YEARLY => (int) round($amount / 12),
            default => $amount,
        };
    }
}
