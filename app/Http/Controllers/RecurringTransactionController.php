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

        // Get accounts with explicit loading
        $accounts = $budget->accounts()->get();
        
        // Debug the data
        info('Accounts being passed to Inertia:', [
            'count' => $accounts->count(),
            'first_account' => $accounts->first(),
        ]);

        return Inertia::render('RecurringTransactions/Index', [
            'budget' => $budget,
            'recurringTransactions' => $recurringTransactions,
            'accounts' => $accounts,
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
            'frequency' => 'required|string|in:daily,weekly,biweekly,monthly,bimonthly,quarterly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'day_of_week' => 'nullable|integer|min:0|max:6|required_if:frequency,weekly,biweekly',
            'day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,monthly,quarterly,bimonthly',
            'first_day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,bimonthly',
            'is_dynamic_amount' => 'required|in:true,false',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int)($validated['amount'] * 100);
        unset($validated['amount']);
        
        // Convert is_dynamic_amount from string to boolean
        $validated['is_dynamic_amount'] = $validated['is_dynamic_amount'] === 'true';

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
            'frequency' => 'required|string|in:daily,weekly,biweekly,monthly,bimonthly,quarterly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'day_of_week' => 'nullable|integer|min:0|max:6|required_if:frequency,weekly,biweekly',
            'day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,monthly,quarterly,bimonthly',
            'first_day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,bimonthly',
            'is_dynamic_amount' => 'required|in:true,false',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int)($validated['amount'] * 100);
        unset($validated['amount']);
        
        // Convert is_dynamic_amount from string to boolean
        $validated['is_dynamic_amount'] = $validated['is_dynamic_amount'] === 'true';

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
     * Duplicate the specified recurring transaction.
     */
    public function duplicate(Budget $budget, RecurringTransactionTemplate $recurring_transaction): RedirectResponse
    {
        $this->authorize('update', $budget);
        
        // Create a copy of the recurring transaction
        $duplicated = $recurring_transaction->replicate();
        $duplicated->description = 'Copy of ' . $recurring_transaction->description;
        
        // Ensure is_dynamic_amount is properly copied
        $duplicated->is_dynamic_amount = $recurring_transaction->is_dynamic_amount;
        
        $duplicated->save();
        
        return redirect()->route('recurring-transactions.index', $budget)
            ->with('message', 'Recurring transaction duplicated successfully');
    }
} 