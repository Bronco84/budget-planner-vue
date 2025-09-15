<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\ProjectionService;
use App\Services\RecurringTransactionService;
use App\Services\HybridAccountService;
use App\Services\VirtualAccountService;
use App\Services\VirtualTransactionService;
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
    public function store(Request $request, VirtualAccountService $virtualAccountService): RedirectResponse
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
            'airtable_base_id' => config('services.airtable.base_id'), // Link to current Airtable base
        ]);

        // Check if we have virtual accounts available
        $virtualAccounts = $virtualAccountService->getAccountsForBudget($budget);
        
        $message = 'Budget created successfully!';
        if ($virtualAccounts->isEmpty()) {
            $message .= ' Once Fintable syncs your accounts to Airtable, they will automatically appear here.';
        } else {
            $message .= ' Found ' . $virtualAccounts->count() . ' account(s) from your connected financial institutions.';
        }

        return redirect()->route('budgets.show', $budget)
            ->with('message', $message);
    }

    /**
     * Display the specified resource.
     */
    public function show(Budget $budget, Request $request, HybridAccountService $hybridAccountService, VirtualAccountService $virtualAccountService, VirtualTransactionService $virtualTransactionService): Response
    {
        // TODO: Add authorization check for budget access
        // Load relationships including plaidAccount with account
        $budget->load([
            'categories.expenses',
            'accounts.plaidAccount'
        ]);

        // Get virtual accounts directly (not stored locally, fetched from Airtable)
        $accounts = $virtualAccountService->getAccountsForBudget($budget);
        $groupedAccounts = $virtualAccountService->getGroupedAccountsForBudget($budget, auth()->id());
        
        // Get the selected account
        $account = null;
        if ($request->filled('account_id')) {
            $accountId = $request->input('account_id');
            // Find account by generated ID
            $account = $accounts->firstWhere('id', (int)$accountId);
        }
        
        // Default to first account if none selected
        if (!$account && $accounts->isNotEmpty()) {
            $account = $accounts->first();
        }
        
        // If still no account, create a placeholder or handle gracefully
        if (!$account) {
            return Inertia::render('Budgets/Show', [
                'budget' => $budget,
                'totalBalance' => 0,
                'accounts' => collect([]),
                'groupedAccounts' => collect([]),
                'transactions' => collect([]),
                'projectedTransactions' => collect([]),
                'projectionParams' => ['months' => 1],
                'categories' => collect([]),
                'filters' => [],
                'selectedAccount' => null,
                'message' => 'No accounts found. Please ensure your accounts are synced from Airtable.',
            ]);
        }

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

        // Get actual transactions including pending ones - filter by budget and account
        $query = $budget->transactions()
            ->select(
                'transactions.*',
                'plaid_transactions.pending as pending',
            )
            ->selectRaw('transactions.date > ? as is_projected', [now()->toDateString()])
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
            // EXCEPT for Airtable imported transactions which are allowed without plaid IDs
            ->where(function($query) {
                $query->where(function($q) {
                    // Future transactions without plaid ID (projected/manual)
                    $q->where('transactions.date', '>', now())->whereNull('transactions.plaid_transaction_id');
                });
                $query->orWhere(function($q) {
                    // Past transactions with plaid ID (imported from Plaid)
                    $q->where('transactions.date', '<=', now())->whereNotNull('transactions.plaid_transaction_id');
                });
                $query->orWhere(function($q) {
                    // Airtable imported transactions (allowed regardless of plaid ID)
                    $q->where('is_airtable_imported', true);
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

        // Get transactions - use hybrid approach for Airtable accounts, database for legacy
        if (isset($account['airtable_id'])) {
            // Use combined historical (Airtable) + projected (local) transactions
            $allTransactions = $virtualTransactionService->getCombinedTransactionsForAccount(
                $budget,
                $account['airtable_id'],
                $startDate,
                $endDate,
                $monthsToProject
            );
            
            // Apply search filter
            if ($request->filled('search')) {
                $search = strtolower($request->input('search'));
                $allTransactions = $allTransactions->filter(function ($transaction) use ($search) {
                    return str_contains(strtolower($transaction['description']), $search) ||
                           str_contains(strtolower($transaction['category']), $search);
                });
            }
            
            // Apply type filter
            if ($request->filled('type')) {
                $type = $request->input('type');
                $allTransactions = $allTransactions->filter(function ($transaction) use ($type) {
                    if ($type === 'income') {
                        return $transaction['amount_in_cents'] > 0;
                    } elseif ($type === 'expense') {
                        return $transaction['amount_in_cents'] < 0;
                    }
                    return true;
                });
            }
            
            // Convert to object-like structure for compatibility and add account info
            $actualTransactions = $allTransactions->map(function ($transaction) use ($account) {
                $transactionObj = (object) $transaction;
                
                // For virtual transactions, create a mock account object for frontend compatibility
                if (!isset($transactionObj->account) && isset($account['name'])) {
                    $transactionObj->account = (object) [
                        'name' => $account['name'],
                        'id' => $account['id'] ?? null,
                        'airtable_id' => $account['airtable_id'] ?? null,
                    ];
                }
                
                return $transactionObj;
            });
            
            // No separate projected transactions needed since they're already included
            $projectedTransactions = collect();
            
        } else {
            // Fallback to database query for legacy accounts
            $query->where('account_id', $account['id']);
            $actualTransactions = $query->get();
        }

        // Get projected transactions for accounts with recurring templates
        $projectedTransactions = collect();
        
        // For accounts that have local Account models (both legacy and hybrid), generate projections
        if (isset($account['airtable_id'])) {
            // Hybrid account: find the corresponding local Account model
            $localAccount = \App\Models\Account::where('budget_id', $budget->id)
                ->where('airtable_account_id', $account['airtable_id'])
                ->first();
                
            if ($localAccount && $localAccount->recurringTransactionTemplates()->exists()) {
                $recurringService = app(RecurringTransactionService::class);
                $projectedTransactions = collect($recurringService->projectTransactions(
                    $localAccount,
                    now()->addDay(),
                    now()->addMonths($monthsToProject)->endOfMonth()
                ));
            }
        } elseif ($account instanceof \App\Models\Account) {
            // Legacy account: get projections directly
            $recurringService = app(RecurringTransactionService::class);
            $projectedTransactions = collect($recurringService->projectTransactions(
                $account,
                now()->addDay(),
                now()->addMonths($monthsToProject)->endOfMonth()
            ));
        }
        
        $projectedTransactions = $projectedTransactions
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

        // For virtual accounts, transactions are already filtered and processed
        if (isset($account['airtable_id'])) {
            // Combine actual transactions with projected transactions
            $allTransactions = $actualTransactions->concat($projectedTransactions)->sortBy('date')->values();
            
            // Split into categories
            $historicalTransactions = $allTransactions->where('is_airtable_imported', true);
            // Keep the projected transactions we generated (don't overwrite)
            $pendingTransactions = collect(); // No pending for virtual accounts
            
        } else {
            // Legacy account processing
            $allTransactions = $actualTransactions->concat($projectedTransactions)->sortBy('date')->values();
            
            // Filter by account_id for legacy accounts
            $accountTransactions = $allTransactions->filter(function($transaction) use ($account) {
                return $transaction->account_id === $account['id'];
            });
            
            $historicalTransactions = $accountTransactions->where('is_projected', false)->filter(function($transaction) {
                return !$transaction->pending;
            });
            
            $pendingTransactions = $accountTransactions->where('is_projected', false)->filter(function($transaction) {
                return $transaction->pending;
            });
            
            $projectedTransactions = $accountTransactions->where('is_projected', true);
        }

        // Calculate running balances using the correct logic:
        // - Current balance represents "today's balance"
        // - Historical transactions go backwards from today
        // - Future transactions go forwards from today
        
        $todayBalance = $account['current_balance_cents'] ?? 0;

        // Separate transactions by time relative to today
        $today = now()->startOfDay();
        $historicalTransactions = $allTransactions->filter(function($transaction) use ($today) {
            return \Carbon\Carbon::parse($transaction->date)->lt($today);
        })->sortByDesc('date'); // Most recent first for backward calculation

        $todayTransactions = $allTransactions->filter(function($transaction) use ($today) {
            return \Carbon\Carbon::parse($transaction->date)->isSameDay($today);
        })->sortBy('date'); // Chronological for forward calculation

        $futureTransactions = $allTransactions->filter(function($transaction) use ($today) {
            return \Carbon\Carbon::parse($transaction->date)->gt($today);
        })->sortBy('date'); // Chronological for forward calculation

        // 1. Calculate historical balances (going backwards from today)
        $runningBalance = $todayBalance;
        foreach ($historicalTransactions as $transaction) {
            // For historical transactions, show the balance AFTER the transaction was applied
            $transaction->running_balance = $runningBalance;
            $runningBalance -= $transaction->amount_in_cents;
        }

        // 2. Calculate today's transactions (going forward from start of day)
        // Note: todayBalance represents the balance at start of day, so we add transactions as they occur
        $runningBalance = $todayBalance;
        foreach ($todayTransactions as $transaction) {
            $runningBalance += $transaction->amount_in_cents;
            $transaction->running_balance = $runningBalance;
        }

        // 3. Calculate future transactions (going forward from today's end balance)
        $runningBalance = $todayBalance;
        // Add any today transactions to get end-of-day balance
        foreach ($todayTransactions as $transaction) {
            $runningBalance += $transaction->amount_in_cents;
        }
        
        foreach ($futureTransactions as $transaction) {
            $runningBalance += $transaction->amount_in_cents;
            $transaction->running_balance = $runningBalance;
        }

        // Sort all transactions by date descending for display
        $allTransactions = $allTransactions->sortByDesc('date')->values();

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

        // Get the total balance across all virtual accounts
        $totalBalance = $accounts->sum('current_balance_cents');
        $totalIncludedBalance = $virtualAccountService->getTotalIncludedBalance($budget, auth()->id());

        return Inertia::render('Budgets/Show', [
            'budget' => $budget,
            'totalBalance' => $totalBalance,
            'totalIncludedBalance' => $totalIncludedBalance,
            'accounts' => $accounts, // Hybrid accounts (synchronized with Airtable)
            'groupedAccounts' => $groupedAccounts, // Accounts organized by category hierarchy
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
            'selectedAccount' => $account,
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

        // Get virtual accounts from Airtable for projections
        $virtualAccountService = app(VirtualAccountService::class);
        $virtualAccounts = $virtualAccountService->getAccountsForBudget($budget);
        
        $projections = collect();

        // Get the RecurringTransactionService
        $recurringTransactionService = app(RecurringTransactionService::class);

        // Project transactions for each virtual account
        foreach ($virtualAccounts as $virtualAccount) {
            // For projections, we use the airtable_account_id to find existing transaction patterns
            $existingTransactions = Transaction::where('airtable_account_id', $virtualAccount['airtable_id'])
                ->where('budget_id', $budget->id)
                ->get();
                
            // Project based on existing transaction patterns
            if ($existingTransactions->isNotEmpty()) {
                // Use the existing projection logic but adapted for virtual accounts
                // This is a simplified version - you might want to enhance this
                $accountProjections = $recurringTransactionService->projectTransactionsForVirtualAccount(
                    $virtualAccount,
                    $existingTransactions,
                    $startDate,
                    $endDate
                );

                $projections = $projections->merge($accountProjections);
            }
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
