<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PayoffPlanDebt;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Services\RecurringTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        // Bound custom dates to the range the 'all' preset covers, so a hand-typed
        // date can't make the per-interval loops below run for centuries.
        $request->validate([
            'date_range' => ['nullable', 'string', Rule::in(['30days', '3months', '6months', 'ytd', 'year', 'all', 'custom'])],
            'start_date' => 'nullable|date|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'end_date' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31', Rule::when($request->filled('start_date'), 'after_or_equal:start_date')],
        ]);

        // Load relationships
        $budget->load([
            'accounts',
            'categories',
            'recurringTransactionTemplates',
            'payoffPlans',
        ]);

        // Get selected account ID from request (null = all accounts)
        $selectedAccountId = $request->input('account_id');
        $selectedAccount = null;

        if ($selectedAccountId) {
            $selectedAccount = $budget->accounts()->find($selectedAccountId);
            if (! $selectedAccount) {
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
            'accounts' => $budget->accounts->map(fn ($account) => [
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

        return match ($dateRange) {
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
            ? collect([$selectedAccount])
            : $budget->accounts()->get();

        $dailyTotals = $this->getDailyTransactionTotals($accounts->pluck('id')->all());

        // Calculate net worth at various points in time
        $dataPoints = [];
        $currentDate = $startDate->copy();

        // Determine interval based on date range
        $daysDiff = (int) $startDate->diffInDays($endDate);
        $interval = match (true) {
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
                $balance = $this->calculateBalanceAtDate(
                    $account,
                    $currentDate,
                    $this->sumDailyTotalsAfter($dailyTotals[$account->id] ?? [], $currentDate),
                );

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
                $balance = $this->calculateBalanceAtDate(
                    $account,
                    $endDate,
                    $this->sumDailyTotalsAfter($dailyTotals[$account->id] ?? [], $endDate),
                );

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
     * Transaction totals per account per stored date, in one grouped query:
     * [account_id => [raw date => sum of amount_in_cents]]. Used by the net
     * worth series instead of one SUM query per account per data point.
     */
    private function getDailyTransactionTotals(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $totals = [];

        Transaction::query()
            ->countedInTotals()
            ->whereIn('account_id', $accountIds)
            ->whereNotNull('date')
            ->groupBy('account_id', 'date')
            ->selectRaw('account_id, date, SUM(amount_in_cents) as total')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$totals) {
                $totals[$row->account_id][(string) $row->date] = (int) $row->total;
            });

        return $totals;
    }

    /**
     * Sum of the daily totals strictly after $date. Compares the raw stored
     * date string with 'Y-m-d' exactly as the SQL `date > ?` filter in
     * calculateBalanceAtDate does.
     */
    private function sumDailyTotalsAfter(array $dailyTotals, Carbon $date): int
    {
        $target = $date->format('Y-m-d');
        $sum = 0;

        foreach ($dailyTotals as $day => $total) {
            if (strcmp((string) $day, $target) > 0) {
                $sum += $total;
            }
        }

        return $sum;
    }

    /**
     * Calculate account balance at a specific date. $transactionsAfterDate may
     * be supplied precomputed (see getDailyTransactionTotals) to skip the query.
     */
    private function calculateBalanceAtDate($account, Carbon $date, ?int $transactionsAfterDate = null): int
    {
        // Get current balance
        $currentBalance = $account->current_balance_cents;

        // Get all transactions after the target date
        $transactionsAfterDate ??= (int) $account->transactions()
            ->countedInTotals()
            ->where('date', '>', $date->format('Y-m-d'))
            ->sum('amount_in_cents');

        // Undo later transactions. Liability balances are positive debt and purchases are
        // negative amounts, so undoing them moves the balance the opposite way to assets.
        return $account->isLiability()
            ? $currentBalance + $transactionsAfterDate
            : $currentBalance - $transactionsAfterDate;
    }

    /**
     * Get cash flow data (income vs expenses over time)
     */
    private function getCashFlowData(Budget $budget, Carbon $startDate, Carbon $endDate, ?Account $selectedAccount = null): array
    {
        // Get monthly cash flow
        $transactionsQuery = $budget->transactions()
            ->countedInTotals()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'));

        // Filter by account if selected
        if ($selectedAccount) {
            $transactionsQuery->where('account_id', $selectedAccount->id);
        }

        // Bucket by month once, rather than rescanning every transaction for each
        // month in the range (the 'all' preset spans hundreds of months).
        $transactionsByMonth = $transactionsQuery->orderBy('date')->get()
            ->groupBy(fn ($transaction) => Carbon::parse($transaction->date)->format('Y-m'));

        $monthlyData = [];
        $currentMonth = $startDate->copy()->startOfMonth();

        while ($currentMonth <= $endDate) {
            $monthTransactions = $transactionsByMonth->get($currentMonth->format('Y-m'), collect());

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

        // Spent per category, summed in SQL over the range's expenses rather
        // than loading every transaction and rescanning it per category. Rows
        // linked by category_id are grouped by id; unlinked legacy rows fall
        // back to their category string (see Transaction::isInCategory).
        $expenses = function () use ($budget, $startDate, $endDate, $selectedAccount) {
            $query = $budget->transactions()
                ->countedInTotals()
                ->where('date', '>=', $startDate->format('Y-m-d'))
                ->where('date', '<=', $endDate->format('Y-m-d'))
                ->where('amount_in_cents', '<', 0);

            if ($selectedAccount) {
                $query->where('account_id', $selectedAccount->id);
            }

            return $query->toBase();
        };

        $spentById = $expenses()
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount_in_cents) as total')
            ->pluck('total', 'category_id');

        // The legacy match is an exact string comparison, as in isInCategory.
        // MySQL's default collation compares case- and trailing-space-
        // insensitively, so group by the binary value there; the names are
        // then looked up exactly (PHP array keys are case-sensitive).
        $legacyKey = in_array($budget->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'CAST(category AS BINARY)'
            : 'category';

        $spentByLegacyName = $expenses()
            ->whereNull('category_id')
            ->whereIn('category', $categories->pluck('name'))
            ->groupByRaw($legacyKey)
            ->selectRaw('MIN(category) as name, SUM(amount_in_cents) as total')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->name => (int) $row->total]);

        $categoryPerformance = [];
        $monthsInRange = $this->monthsInRange($startDate, $endDate);

        foreach ($categories as $category) {
            $spent = abs((int) ($spentById[$category->id] ?? 0))
                + abs((int) ($spentByLegacyName[$category->name] ?? 0));
            // Category amounts are monthly dollars; prorate them over the selected range.
            $allocated = (int) round($category->amount * 100 * $monthsInRange);

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
        usort($categoryPerformance, fn ($a, $b) => $b['spent'] <=> $a['spent']);

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
     * Number of months covered by an inclusive date range, counting each partial
     * calendar month as the fraction of its days that fall inside the range.
     */
    private function monthsInRange(Carbon $startDate, Carbon $endDate): float
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();
        $months = 0.0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $segmentEnd = $monthEnd->lt($end) ? $monthEnd : $end;
            $months += ((int) $cursor->diffInDays($segmentEnd) + 1) / $cursor->daysInMonth;
            $cursor = $monthEnd->addDay();
        }

        return $months;
    }

    /**
     * Get spending patterns data (by category and merchant)
     */
    private function getSpendingPatternsData(Budget $budget, Carbon $startDate, Carbon $endDate, ?Account $selectedAccount = null): array
    {
        $transactionsQuery = $budget->transactions()
            ->countedInTotals()
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'))
            ->where('amount_in_cents', '<', 0)
            ->with(['plaidTransaction', 'budgetCategory']);

        // Filter by account if selected
        if ($selectedAccount) {
            $transactionsQuery->where('account_id', $selectedAccount->id);
        }

        $transactions = $transactionsQuery->get();

        // Group by category
        $byCategory = $transactions->groupBy('category_name')->map(function ($categoryTransactions) {
            return [
                'category' => $categoryTransactions->first()->category_name ?: 'Uncategorized',
                'total' => abs($categoryTransactions->sum('amount_in_cents')),
                'count' => $categoryTransactions->count(),
                'average' => abs($categoryTransactions->avg('amount_in_cents')),
            ];
        })->sortByDesc('total')->values()->take(10)->toArray();

        // Group by merchant (from Plaid data)
        $byMerchant = $transactions->filter(function ($transaction) {
            return $transaction->plaidTransaction && $transaction->plaidTransaction->merchant_name;
        })->groupBy(function ($transaction) {
            return $transaction->plaidTransaction->merchant_name;
        })->map(function ($merchantTransactions, $merchant) {
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
        $payoffPlanDebtsQuery = PayoffPlanDebt::whereHas('payoffPlan', function ($query) use ($budget) {
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

        // Value each active template at the amount projections use, so dynamic-amount
        // templates (stored as 0) are included at their calculated amount.
        $recurringTransactionService = app(RecurringTransactionService::class);
        $templates = $budget->recurringTransactionTemplates()
            ->active()
            ->with(['account', 'transactions', 'linkedCreditCard.plaidAccount', 'rules'])
            ->get()
            ->map(function ($template) use ($recurringTransactionService) {
                $amount = $recurringTransactionService->projectedAmount($template);

                return [
                    'id' => $template->id,
                    'account_id' => $template->account_id,
                    'description' => $template->friendly_label ?: $template->description,
                    'amount_in_cents' => $amount,
                    'frequency' => $template->frequency,
                    'monthly_amount' => $this->calculateMonthlyAmountForTemplate($template, $amount),
                    'account_name' => $template->account?->name,
                    'category' => $template->category,
                ];
            });

        $incomeTemplates = $templates->filter(fn ($item) => $item['amount_in_cents'] > 0)->values();
        $expenseTemplates = $templates->filter(fn ($item) => $item['amount_in_cents'] < 0)->values();

        // Get credit cards with autopay enabled
        $autopayAccounts = $budget->accounts()
            ->where('autopay_enabled', true)
            ->whereNotNull('autopay_source_account_id')
            ->with(['plaidAccount', 'autopaySourceAccount'])
            ->get()
            ->filter(fn ($account) => $account->hasActiveAutopay())
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
    private function calculateMonthlyAmountForTemplate(RecurringTransactionTemplate $template, int $amount): int
    {

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
