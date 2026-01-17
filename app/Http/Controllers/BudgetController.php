<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\ProjectionService;
use App\Services\RecurringTransactionService;
use App\Services\PlaidService;
use App\Services\BudgetService;
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
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $user = Auth::user();

        // Create the budget
        /** @var Budget $budget */
        $budget = $user->budgets()->create([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'color' => $validated['color'] ?? '#6366f1',
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
        $budgetService = app(BudgetService::class);
        $user = Auth::user();

        // Load relationships including plaidAccount with connection and holdings
        $budget->load([
            'categories',
            'accounts.plaidAccount.plaidConnection',
            'accounts.plaidAccount.holdings.security',
        ]);

        // Initialize variables for account-dependent data
        $account = null;
        $accountTransactions = null;
        $projectedTransactions = collect();
        $monthlyProjectedCashFlow = 0;
        
        // Get saved projection months preference for this budget, default to 1
        $preferenceKey = "budget_{$budget->id}_projection_months";
        $savedProjectionMonths = (int) $user->getPreference($preferenceKey, 1);
        $monthsToProject = $savedProjectionMonths;

        // Only process account data if budget has accounts
        if ($budget->accounts->count() > 0) {
            // Get the selected account based on request or user preferences
            $account = $budgetService->getSelectedAccount(
                $budget,
                $request->input('account_id'),
                $user->getAccountTypeOrder()
            );

            if (!$account) {
                throw new \Exception('No accounts found for this budget.');
            }

            // Get projection and date range parameters
            // If request has projection_months, use it and save as preference
            if ($request->has('projection_months')) {
                $monthsToProject = (int) $request->input('projection_months');
                // Save the preference for next time
                if ($monthsToProject !== $savedProjectionMonths) {
                    $user->setPreference($preferenceKey, $monthsToProject);
                }
            }
            $dateRange = $request->input('date_range', '90');
            $startDate = $budgetService->parseDateRange($dateRange, $request->input('start_date'));
            $endDate = now()->addMonths($monthsToProject)->endOfMonth();

            // Get all transactions (actual, pending, and projected)
            $allTransactions = $budgetService->getAccountTransactions(
                $account,
                $startDate,
                $endDate,
                $monthsToProject
            );

            // Calculate running balances for all transactions
            // Note: Uses different calculation for liabilities vs assets
            $allTransactions = $budgetService->calculateRunningBalances(
                $allTransactions,
                $account
            );

            // Get projected transactions for passing to view separately
            $projectedTransactions = $allTransactions
                ->where('account_id', $account->id)
                ->where('is_projected', true);

            // Get per_page from request, validate and constrain to reasonable limits
            $perPage = (int) $request->input('per_page', 50);
            $perPage = max(10, min(200, $perPage)); // Constrain between 10 and 200

            // Paginate the transactions
            $accountTransactions = $budgetService->paginateTransactions(
                $allTransactions,
                $request->input('page', 1),
                $perPage,
                $request->url(),
                $request->query()
            );

            // Calculate projected monthly cash flow for the selected account
            $monthlyProjectedCashFlow = $budgetService->calculateMonthlyProjectedCashFlow($account);
        }

        // Calculate the total balance across all accounts
        $totalBalance = $budgetService->calculateTotalBalance($budget);

        // Load properties with their linked accounts
        $budget->load('properties.linkedAccounts');

        return Inertia::render('Budgets/Show', [
            'budget' => $budget,
            'totalBalance' => $totalBalance,
            'accounts' => $budget->accounts,
            'properties' => $budget->properties,
            'selectedAccountId' => $account?->id,
            'transactions' => $accountTransactions,
            'projectedTransactions' => $projectedTransactions,
            'projectionParams' => [
                'months' => $monthsToProject,
            ],
            'userAccountTypeOrder' => $user->getAccountTypeOrder(),
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
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
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
