<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\ProjectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;

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
            'account_name' => 'required|string|max:255',
            'account_type' => 'required|string|in:checking,savings,credit,investment,other',
            'starting_balance' => 'required|numeric',
        ]);

        // Create the budget with proper type
        /** @var Budget $budget */
        $budget = Auth::user()->budgets()->create([
            'name' => $validated['name'],
            'description' => $validated['description'],
        ]);

        // Create the initial account with proper type
        /** @var Account $account */
        $account = $budget->accounts()->create([
            'name' => $validated['account_name'],
            'type' => $validated['account_type'],
            'current_balance_cents' => $validated['starting_balance'] * 100,
            'balance_updated_at' => now(),
            'include_in_budget' => true,
        ]);

        // Create an initial transaction for this balance
        $account->transactions()->create([
            'budget_id' => $budget->id,
            'description' => 'Initial Balance',
            'amount_in_cents' => $validated['starting_balance'] * 100,
            'date' => now(),
            'category' => 'Starting Balance',
            'is_reconciled' => true,
        ]);

        // Set this account as the starting balance account for the budget
        $budget->update(['starting_balance_account_id' => $account->id]);

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Budget created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Budget $budget, Request $request): Response
    {
        // Load relationships including plaidAccount with account
        $budget->load([
            'categories.expenses', 
            'accounts.plaidAccount'
        ]);
        
        // Make sure accounts are loaded with their plaidAccount relationship
        $accounts = $budget->accounts->each(function ($account) {
            // Force loading of plaidAccount to ensure it's available
            $account->load('plaidAccount');
        });
        
        // Get query parameters for filtering
        $search = $request->input('search');
        $category = $request->input('category');
        $timeframe = $request->input('timeframe');
        $page = $request->input('page', 1);
        
        // Get projection parameters
        $includeProjections = $request->boolean('include_projections', false);
        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : Carbon::today();
        $months = (int) $request->input('projection_months', 1);
        
        // Treat months=0 as disabling projections
        if ($months === 0) {
            $includeProjections = false;
        }
        
        // Build transaction query
        $transactionQuery = $budget->transactions()->with('account');
        
        // Apply search filter if provided
        if ($search) {
            $transactionQuery->where(function($query) use ($search) {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }
        
        // Apply category filter if provided
        if ($category) {
            $transactionQuery->where('category', $category);
        }
        
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
        
        // Order by date descending
        $transactionQuery->orderByDesc('date');
        
        // Get projected transactions if requested
        $projectedTransactions = collect();
        if ($includeProjections) {
            $projectionService = new ProjectionService();
            $projections = $projectionService->projectBudgetTransactions($budget, $startDate, $months);
            
            // Flatten the nested transaction structure from projections
            foreach ($projections['transactions'] as $monthData) {
                foreach ($monthData as $dayData) {
                    foreach ($dayData as $transaction) {
                        if ($transaction->is_projected) {
                            $projectedTransactions->push($transaction);
                        }
                    }
                }
            }
        }
        
        // Get base transactions
        $actualTransactions = $transactionQuery->paginate(10)->withQueryString();
        
        // If projections are included, we need to merge them with actual transactions on the client side
        
        // Get unique categories for filter dropdown
        $categories = $budget->transactions()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
        
        // Get the total balance across all accounts
        $totalBalance = $budget->accounts->sum('current_balance_cents') / 100;
        
        return Inertia::render('Budgets/Show', [
            'budget' => $budget,
            'totalBalance' => $totalBalance,
            'accounts' => $budget->accounts,
            'transactions' => $actualTransactions,
            'projectedTransactions' => $includeProjections ? $projectedTransactions : null,
            'projectionParams' => [
                'includeProjections' => $includeProjections,
                'startDate' => $startDate->format('Y-m-d'),
                'months' => $months,
            ],
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'category' => $category,
                'timeframe' => $timeframe,
            ],
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
            'start_date' => 'required|date',
            'months' => 'integer|min:1|max:24',
        ]);
        
        $startDate = Carbon::parse($validated['start_date']);
        $months = $validated['months'] ?? 6;
        
        $projectionService = new ProjectionService();
        $projections = $projectionService->projectBudgetTransactions($budget, $startDate, $months);
        
        return Inertia::render('Budgets/Projections', [
            'budget' => $budget,
            'projections' => $projections,
            'params' => [
                'start_date' => $startDate->format('Y-m-d'),
                'months' => $months,
            ],
        ]);
    }
}
