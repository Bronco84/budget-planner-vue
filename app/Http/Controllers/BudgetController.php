<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\ProjectionService;
use App\Services\RecurringTransactionService;
use App\Services\PlaidService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class BudgetController extends Controller
{
    /**
     * Create the controller instance.
     */
    public function __construct()
    {
        $this->authorizeResource(Budget::class, 'budget');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $budgets = Auth::user()->budgets()
            ->with(['categories', 'categories.expenses', 'accounts'])
            ->get();

        return Inertia::render('Budgets/Index', [
            'budgets' => $budgets
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Budgets/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        // Create the budget
        /** @var Budget $budget */
        $budget = Auth::user()->budgets()->create([
            'name' => $validated['name'],
            'description' => $validated['description'],
        ]);

        return redirect()->route('budgets.setup', $budget)
            ->with('message', 'Budget created! Now let\'s set up your accounts.');
    }

    /**
     * Show the account setup page for a newly created budget.
     */
    public function setup(Budget $budget): Response|RedirectResponse
    {
        $this->authorize('view', $budget);

        // If budget already has accounts, redirect to budget show
        if ($budget->accounts()->exists()) {
            return redirect()->route('budgets.show', $budget)
                ->with('message', 'Budget already has accounts configured.');
        }

        try {
            $plaidService = app(PlaidService::class);
            $linkToken = $plaidService->createLinkToken(Auth::id());
        } catch (\Exception $e) {
            $linkToken = null;
            \Log::warning('Failed to create Plaid link token for setup', [
                'error' => $e->getMessage(),
                'budget_id' => $budget->id
            ]);
        }

        return Inertia::render('Budgets/Setup', [
            'budget' => $budget,
            'linkToken' => $linkToken,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Budget $budget, Request $request): Response
    {
        // TODO: Add authorization check for budget access
        // Load relationships including plaidAccount with account
        $budget->load([
            'categories.expenses',
            'accounts.plaidAccount'
        ]);

        // Check if budget has accounts
        if (!$budget->accounts->count()) {
            return redirect()->route('budgets.setup', $budget)
                ->with('message', 'Add your first account to get started with this budget.');
        }

        // Get the selected account (or first account if none selected)
        $account = $budget->accounts()
            ->when(
                $request->filled('account_id'),
                fn($query) => $query->where('id', $request->input('account_id')),
            )->firstOrFail();

        // Get query parameters for filtering
        $search = $request->input('search');
        $type = $request->input('type');
        $category = $request->input('category');
        $pending = $request->input('pending');
        $timeframe = $request->input('timeframe');

        // Get projection parameters
        $monthsToProject = (int) $request->input('projection_months', 1);

        // Build transaction query
        $transactionQuery = $budget->transactions()->with('account');

        // Apply search filter if provided
        $transactionQuery->when(
            isset($search),
            fn ($query) => $query->where(function($query) use ($search) {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            })
        );

        $transactionQuery->when(
            isset($category),
            fn ($query) => $query->where('category', $category)
        );

        // Apply time filter if provided
        if ($timeframe) {
            $now = Carbon::now();
            switch ($timeframe) {
                case 'this_month':
                    $transactionQuery->whereMonth('date', $now->month)
                        ->whereYear('date', $now->year);
                    break;
                case 'last_month':
                    $lastMonth = $now->copy()->subMonth();
                    $transactionQuery->whereMonth('date', $lastMonth->month)
                        ->whereYear('date', $lastMonth->year);
                    break;
                case 'last_3_months':
                    $threeMonthsAgo = $now->copy()->subMonths(3);
                    $transactionQuery->where('date', '>=', $threeMonthsAgo->startOfDay());
                    break;
                case 'this_year':
                    $transactionQuery->whereYear('date', $now->year);
                    break;
            }
        }

        // Get the date range from the filter
        $dateRange = $request->input('date_range', '90');
        $startDate = match($dateRange) {
            '7' => now()->subDays(7),
            '30' => now()->subDays(30),
            '90' => now()->subDays(90),
            'custom' => $request->input('start_date') ? Carbon::parse($request->input('start_date')) : now()->subDays(90),
            default => now()->startOfYear(),
        };

        $endDate = now()->addMonths($monthsToProject)->endOfMonth();

        // Get actual transactions including pending ones
        $query = $account->transactions()
            ->select(
                'transactions.*',
                'plaid_transactions.pending as pending',
            )
            ->selectRaw('transactions.date > CURDATE() as is_projected')
            ->selectRaw('false as is_recurring')
            ->with(['account', 'plaidTransaction'])
            ->leftJoin('plaid_transactions', 'transactions.plaid_transaction_id', '=', 'plaid_transactions.plaid_transaction_id')
            ->where(function($query) use ($startDate, $endDate) {
                $query->where('transactions.date', '>=', $startDate)
                    ->where('transactions.date', '<=', $endDate)
                    ->orWhereHas('plaidTransaction', function($q) {
                        // pending transactions should always be included
                        // since they are technically "future" transactions without a date
                        $q->where('pending', true);
                    });
            })
            // Remove any transaction that doesn't have a plaid ID if trx date is in the past
            // We consider plaid feed as the source of truth for transactions in the past
            ->where(function($query) {
                $query->where(function($q) {
                    $q->where('transactions.date', '>', now())->whereNull('transactions.plaid_transaction_id');
                });
                $query->orWhere(function($q) {
                    $q->where('transactions.date', '<=', now())->whereNotNull('transactions.plaid_transaction_id');
                });
            })
            ->when($request->filled('type'), function($query, $type) {
                match ($type) {
                    'income' => $query->where('amount_in_cents', '>', 0),
                    'expense' => $query->where('amount_in_cents', '<', 0),
                    'recurring' => $query->whereNotNull('recurring_transaction_template_id'),
                };
            })
            ->when($request->filled('pending'), function($query) use ($request) {
                $isPending = filter_var($request->input('pending'), FILTER_VALIDATE_BOOLEAN);
                if ($isPending) {
                    $query->whereHas('plaidTransaction', function($q) {
                        $q->where('pending', true);
                    });
                } else {
                    $query->where(function($q) {
                        $q->whereDoesntHave('plaidTransaction')
                          ->orWhereHas('plaidTransaction', function($subQ) {
                              $subQ->where('pending', false);
                          });
                    });
                }
            })
            ->when($request->filled('category'), fn($query, $category) => $query->where('category', $category))
            ->when($request->filled('search'), function($q) use ($request) {
                $q->where('description', 'like', "%{$request->input('search')}%")
                    ->orWhere('category', 'like', "%{$request->input('search')}%")
                    ->orWhere('notes', 'like', "%{$request->input('search')}%");
            });

        // Get actual transactions
        $actualTransactions = $query->get();

        // Get projected transactions
        $recurringService = app(RecurringTransactionService::class);
        $projectedTransactions = collect($recurringService->projectTransactions(
            $account,
            now()->addDay(),
            now()->addMonths($monthsToProject)->endOfMonth()
        ))
            ->map(function ($transaction) use ($budget) {
                $model = new Transaction($transaction);
                $model->account = $transaction['account'] ?? null;
                $model->budget_id = $budget->id;
                $model->is_projected = true;
                $model->is_recurring = true;
                $model->date = Carbon::parse($transaction['date']);
                $model->account_id = $transaction['account_id'] ?? null;
                $model->recurring_transaction_template_id = $transaction['recurring_transaction_template_id'] ?? null;
                $model->is_dynamic_amount = $transaction['is_dynamic_amount'] ?? false;
                $model->amount_in_cents = $transaction['amount_in_cents'] ?? 0;
                return $model;
            });

        // Merge and sort all transactions
        $allTransactions = $actualTransactions->concat($projectedTransactions)->sortBy('date')
            ->values();

        // Split transactions into actual, pending, and projected for this account
        $accountTransactions = $allTransactions->where('account_id', $account->id);

        // Get actual (non-pending) transactions
        $actualTransactions = $allTransactions
            ->where('is_projected', false)
            ->filter(function($transaction) {
                return !$transaction->pending;
            });

        // Get pending transactions
        $pendingTransactions = $accountTransactions
            ->where('is_projected', false)
            ->filter(function($transaction) {
                return $transaction->pending;
            });

        // Get projected transactions
        $projectedTransactions = $accountTransactions
            ->where('is_projected', true);

        $runningBalance = $account->current_balance_cents;

        // Process actual transactions in reverse chronological order
        foreach ($actualTransactions->reverse() as $transaction) {
            $transaction->running_balance = $runningBalance;
            $runningBalance = $runningBalance - $transaction->amount_in_cents;
        }

        // Reset balance to current for pending and projected transactions
        $runningBalance = $account->current_balance_cents;

        // Process pending transactions in chronological order
        foreach ($pendingTransactions as $transaction) {
            $runningBalance += $transaction->amount_in_cents;
            $transaction->running_balance = $runningBalance;
        }

        // Process projected transactions in chronological order
        foreach ($projectedTransactions as $transaction) {
            $runningBalance += $transaction->amount_in_cents;
            $transaction->running_balance = $runningBalance;
        }

        // Sort all transactions by date descending for display
        $allTransactions = $allTransactions->reverse()->values();

        // Paginate the merged collection
        $perPage = 50;
        $page = $request->input('page', 1);
        $offset = ($page - 1) * $perPage;

        $accountTransactions = new LengthAwarePaginator(
            $allTransactions->slice($offset, $perPage),
            $allTransactions->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Get unique categories for filter dropdown
        $categories = $budget->transactions()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        // Get the total balance across all accounts
        $totalBalance = $budget->accounts->sum('current_balance_cents');

        return Inertia::render('Budgets/Show', [
            'budget' => $budget,
            'totalBalance' => $totalBalance,
            'accounts' => $budget->accounts,
            'transactions' => $accountTransactions,
            'projectedTransactions' => $projectedTransactions,
            'projectionParams' => [
                'months' => $monthsToProject,
            ],
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'type' => $type,
                'category' => $category,
                'pending' => $pending,
                'timeframe' => $timeframe,
                'account_id' => $request->input('account_id'),
            ],
            'userAccountTypeOrder' => Auth::user()->getAccountTypeOrder(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Budget $budget): Response
    {
        return Inertia::render('Budgets/Edit', [
            'budget' => $budget
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Budget $budget): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $budget->update($validated);

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Budget updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Budget $budget): RedirectResponse
    {
        $budget->delete();

        return redirect()->route('budgets.index')
            ->with('message', 'Budget deleted successfully');
    }

    /**
     * Display monthly statistics for a budget
     */
    public function monthlyStatistics(Budget $budget, $month = null, $year = null): Response
    {
        // Set default month and year if not provided
        $month = $month ?? now()->month;
        $year = $year ?? now()->year;

        $statistics = $budget->getMonthlyStatistics($month, $year);

        return Inertia::render('Budgets/MonthlyStatistics', [
            'budget' => $budget,
            'statistics' => $statistics,
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * Display yearly statistics for a budget
     */
    public function yearlyStatistics(Budget $budget, Request $request): Response
    {
        $year = $request->input('year', now()->year);
        $statistics = $budget->getYearlyStatistics($year);

        return Inertia::render('Budgets/YearlyStatistics', [
            'budget' => $budget,
            'statistics' => $statistics,
            'year' => $year,
        ]);
    }

    /**
     * Get projected transactions for the budget
     */
    public function projections(Budget $budget, Request $request): Response
    {
        $this->authorize('view', $budget);

        $validated = $request->validate([
            'months' => 'integer|min:1|max:24',
        ]);

        $startDate = Carbon::today();
        $months = $validated['months'] ?? 6;
        $endDate = $startDate->copy()->addMonths($months);

        // Get all accounts for this budget
        $accounts = $budget->accounts;
        $projections = collect();

        // Get the RecurringTransactionService
        $recurringTransactionService = app(RecurringTransactionService::class);

        // Project transactions for each account
        foreach ($accounts as $account) {
            $accountProjections = $recurringTransactionService->projectTransactions(
                $account,
                $startDate,
                $endDate
            );

            $projections = $projections->merge($accountProjections);
        }

        return Inertia::render('Budgets/Projections', [
            'budget' => $budget,
            'projections' => $projections,
            'params' => [
                'months' => $months,
            ],
        ]);
    }

    /**
     * Handle filtering of transactions (to work around GET param issues)
     */
    public function filter(Request $request, Budget $budget): RedirectResponse
    {
        // Log received parameters
        \Log::info('Filtering budget transactions', [
            'budget_id' => $budget->id,
            'params' => $request->all()
        ]);

        // Extract filter parameters
        $params = [
            'account_id' => $request->input('account_id'),
            'search' => $request->input('search'),
            'type' => $request->input('type'),
            'category' => $request->input('category'),
            'pending' => $request->input('pending'),
            'timeframe' => $request->input('timeframe'),
            'projection_months' => $request->input('projection_months', 1),
        ];

        // Remove empty parameters
        $params = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // Redirect to show with query parameters
        return redirect()->route('budgets.show', array_merge(
            ['budget' => $budget->id],
            $params
        ));
    }
}
