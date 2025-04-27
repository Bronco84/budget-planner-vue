<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Services\RecurringTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecurringTransactionController extends Controller
{
    protected RecurringTransactionService $recurringTransactionService;
    
    /**
     * Create a new controller instance.
     */
    public function __construct(RecurringTransactionService $recurringTransactionService)
    {
        $this->recurringTransactionService = $recurringTransactionService;
    }
    
    /**
     * Display a listing of the recurring transactions for a budget.
     */
    public function index(Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $recurringTransactions = $budget->recurringTransactionTemplates()
            ->with('account')
            ->orderBy('description')
            ->get();

        return Inertia::render('RecurringTransactions/Index', [
            'budget' => $budget,
            'recurringTransactions' => $recurringTransactions,
        ]);
    }
    
    /**
     * Show the form for creating a new recurring transaction.
     */
    public function create(Budget $budget): Response
    {
        $this->authorize('update', $budget);

        return Inertia::render('RecurringTransactions/Create', [
            'budget' => $budget,
            'accounts' => $budget->accounts,
        ]);
    }
    
    /**
     * Store a newly created recurring transaction in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'account_id' => 'required|exists:accounts,id',
            'category' => 'required|string|max:255',
            'frequency' => 'required|string|in:daily,weekly,biweekly,monthly,quarterly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'day_of_week' => 'nullable|integer|min:0|max:6|required_if:frequency,weekly,biweekly',
            'day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,monthly,quarterly',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int)($validated['amount'] * 100);
        unset($validated['amount']);

        // Create the recurring transaction
        $budget->recurringTransactionTemplates()->create($validated);

        return redirect()->route('recurring-transactions.index', $budget)
            ->with('message', 'Recurring transaction created successfully');
    }
    
    /**
     * Show the form for editing the specified recurring transaction.
     */
    public function edit(Budget $budget, RecurringTransactionTemplate $recurring_transaction): Response
    {
        $this->authorize('update', $budget);

        return Inertia::render('RecurringTransactions/Edit', [
            'budget' => $budget,
            'accounts' => $budget->accounts,
            'recurringTransaction' => $recurring_transaction,
        ]);
    }
    
    /**
     * Update the specified recurring transaction in storage.
     */
    public function update(Request $request, Budget $budget, RecurringTransactionTemplate $recurring_transaction): RedirectResponse
    {
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'account_id' => 'required|exists:accounts,id',
            'category' => 'required|string|max:255',
            'frequency' => 'required|string|in:daily,weekly,biweekly,monthly,quarterly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'day_of_week' => 'nullable|integer|min:0|max:6|required_if:frequency,weekly,biweekly',
            'day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,monthly,quarterly',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int)($validated['amount'] * 100);
        unset($validated['amount']);

        // Update the recurring transaction
        $recurring_transaction->update($validated);

        return redirect()->route('recurring-transactions.index', $budget)
            ->with('message', 'Recurring transaction updated successfully');
    }
    
    /**
     * Remove the specified recurring transaction from storage.
     */
    public function destroy(Budget $budget, RecurringTransactionTemplate $recurring_transaction): RedirectResponse
    {
        $this->authorize('update', $budget);

        $recurring_transaction->delete();

        return redirect()->route('recurring-transactions.index', $budget)
            ->with('message', 'Recurring transaction deleted successfully');
    }
    
    /**
     * Generate transactions from templates.
     */
    public function generate(Budget $budget): RedirectResponse
    {
        $result = $this->recurringTransactionService->generateUpcomingTransactions($budget);
        
        return redirect()->back()->with('message', 
            'Generated ' . $result['generated'] . ' transactions. ' . 
            ($result['errors'] > 0 ? $result['errors'] . ' templates had errors.' : ''));
    }
} 