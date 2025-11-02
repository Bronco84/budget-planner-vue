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
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();
        $activeBudget = $user->getActiveBudget();

        // If no budgets exist, redirect to create page
        if (!$activeBudget) {
            return redirect()->route('budgets.create');
        }

        // Redirect to active budget
        return redirect()->route('budgets.show', $activeBudget);
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

        $user = Auth::user();

        // Create the budget
        /** @var Budget $budget */
        $budget = $user->budgets()->create([
            'name' => $validated['name'],
            'description' => $validated['description'],
        ]);

        // Set as active budget if user has no active budget
        if (!$user->getActiveBudget()) {
            $user->setActiveBudget($budget->id);
        }

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
    public function show(Budget $budget, Request $request): Response|RedirectResponse
    {
        // TODO: Add authorization check for budget access
        // Load relationships including plaidAccount with connection
        $budget->load([
            'categories.expenses',
            'accounts.plaidAccount.plaidConnection'
        ]);

        // Initialize variables for account-dependent data
        $account = null;
        $accountTransactions = null;
        $projectedTransactions = collect();
        $monthlyProjectedCashFlow = 0;
        $totalBalance = 0;

        // Only process account data if budget has accounts
        if ($budget->accounts->count() > 0) {
            // Get the selected account (or first account if none selected)
            if ($request->filled('account_id')) {
                $account = $budget->accounts()->where('id', $request->input('account_id'))->firstOrFail();
            } else {
                // Get the first account according to user's preferred account type order
                $userAccountTypeOrder = Auth::user()->getAccountTypeOrder();
                $accounts = $budget->accounts()->get();

                // Group accounts by type
                $accountsByType = $accounts->groupBy('type');

                // Find the first account type that has accounts, according to user preference
                $account = null;
                foreach ($userAccountTypeOrder as $type) {
                    if ($accountsByType->has($type) && $accountsByType[$type]->isNotEmpty()) {
                        $account = $accountsByType[$type]->first();
                        break;
                    }
                }

                // Fallback to just the first account if no match found
                if (!$account) {
                    $account = $accounts->first();
                }

                if (!$account) {
                    throw new \Exception('No accounts found for this budget.');
                }
            }

            // Get projection parameters
            $monthsToProject = (int) $request->input('projection_months', 1);

            // Get the date range
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
                ->selectRaw("transactions.date > ? as is_projected", [now()->toDateString()])
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

            // Calculate the total balance across all accounts
            // Assets are added, liabilities are subtracted, excluded accounts are ignored
            $totalBalance = $budget->accounts
                ->filter(fn($account) => !$account->exclude_from_total_balance)
                ->reduce(function ($total, $account) {
                    if ($account->isLiability()) {
                        // Subtract liabilities (mortgages, lines of credit, etc.)
                        return $total - $account->current_balance_cents;
                    } else {
                        // Add assets (checking, savings, etc.)
                        return $total + $account->current_balance_cents;
                    }
                }, 0);

            // Calculate projected monthly cash flow for the selected account
            // This is based on recurring transactions for the next month
            $monthlyProjectedCashFlow = $recurringService->calculateMonthlyProjectedCashFlow($account);
        }

        return Inertia::render('Budgets/Show', [
            'budget' => $budget,
            'totalBalance' => $totalBalance,
            'accounts' => $budget->accounts,
            'selectedAccountId' => $account?->id,
            'transactions' => $accountTransactions,
            'projectedTransactions' => $projectedTransactions,
            'projectionParams' => [
                'months' => $monthsToProject ?? 1,
            ],
            'userAccountTypeOrder' => Auth::user()->getAccountTypeOrder(),
            'monthlyProjectedCashFlow' => $monthlyProjectedCashFlow,
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
        $user = Auth::user();
        $activeBudget = $user->getActiveBudget();

        // If deleting the active budget, clear the preference
        // The redirect to budgets.index will set a new active budget automatically
        if ($activeBudget && $activeBudget->id === $budget->id) {
            $user->setActiveBudget(null);
        }

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
}
