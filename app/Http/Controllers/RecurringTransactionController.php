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
            'min_amount' => 'nullable|numeric',
            'max_amount' => 'nullable|numeric',
            'average_amount' => 'nullable|numeric',
            'rules' => 'array',
            'rules.*.field' => 'required|string|in:description,amount,category',
            'rules.*.operator' => 'required|string|in:contains,equals,starts_with,ends_with,regex,greater_than,less_than',
            'rules.*.value' => 'required|string|max:255',
            'rules.*.is_case_sensitive' => 'boolean',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int)($validated['amount'] * 100);
        unset($validated['amount']);
        
        // Convert dynamic amount values to cents if provided
        if (isset($validated['min_amount']) && $validated['min_amount'] !== null) {
            $validated['min_amount'] = (int)($validated['min_amount'] * 100);
        }
        
        if (isset($validated['max_amount']) && $validated['max_amount'] !== null) {
            $validated['max_amount'] = (int)($validated['max_amount'] * 100);
        }
        
        // Convert is_dynamic_amount from string to boolean
        $validated['is_dynamic_amount'] = $validated['is_dynamic_amount'] === 'true';

        // Extract rules from validated data
        $rules = $validated['rules'] ?? [];
        unset($validated['rules']);

        // Create the recurring transaction
        $recurringTransaction = $budget->recurringTransactionTemplates()->create($validated);
        
        // Create rules if this is a dynamic amount transaction
        if ($validated['is_dynamic_amount'] && !empty($rules)) {
            foreach ($rules as $index => $ruleData) {
                // Add priority based on the order of rules
                $ruleData['priority'] = $index + 1;
                $recurringTransaction->rules()->create($ruleData);
            }
        }

        return redirect()->route('recurring-transactions.index', $budget)
            ->with('message', 'Recurring transaction created successfully');
    }
    
    /**
     * Show the form for editing the specified recurring transaction.
     */
    public function edit(Budget $budget, RecurringTransactionTemplate $recurring_transaction): Response
    {
        $this->authorize('update', $budget);

        // Load the rules associated with this recurring transaction
        $rules = $recurring_transaction->rules()->orderBy('priority')->get();

        return Inertia::render('RecurringTransactions/Edit', [
            'budget' => $budget,
            'accounts' => $budget->accounts,
            'recurringTransaction' => $recurring_transaction,
            'rules' => $rules,
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
            'min_amount' => 'nullable|numeric',
            'max_amount' => 'nullable|numeric',
            'average_amount' => 'nullable|numeric',
            'rules' => 'array',
            'rules.*.id' => 'nullable|integer|exists:recurring_transaction_rules,id',
            'rules.*.field' => 'required|string|in:description,amount,category',
            'rules.*.operator' => 'required|string|in:contains,equals,starts_with,ends_with,regex,greater_than,less_than',
            'rules.*.value' => 'required|string|max:255',
            'rules.*.is_case_sensitive' => 'boolean',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int)($validated['amount'] * 100);
        unset($validated['amount']);
        
        // Convert dynamic amount values to cents if provided
        if (isset($validated['min_amount']) && $validated['min_amount'] !== null) {
            $validated['min_amount'] = (int)($validated['min_amount'] * 100);
        }
        
        if (isset($validated['max_amount']) && $validated['max_amount'] !== null) {
            $validated['max_amount'] = (int)($validated['max_amount'] * 100);
        }
        
        // Convert is_dynamic_amount from string to boolean
        $validated['is_dynamic_amount'] = $validated['is_dynamic_amount'] === 'true';

        // Extract rules from validated data
        $rules = $validated['rules'] ?? [];
        unset($validated['rules']);

        // Update the recurring transaction
        $recurring_transaction->update($validated);
        
        // Handle rules if this is a dynamic amount transaction
        if ($validated['is_dynamic_amount']) {
            // Get existing rule IDs to determine which ones to delete
            $existingRuleIds = $recurring_transaction->rules()->pluck('id')->toArray();
            $updatedRuleIds = [];
            
            // Update or create rules
            foreach ($rules as $ruleData) {
                $ruleId = $ruleData['id'] ?? null;
                
                // Remove id from the data before create/update
                if (isset($ruleData['id'])) {
                    unset($ruleData['id']);
                }
                
                if ($ruleId) {
                    // Update existing rule if it belongs to this template
                    $rule = $recurring_transaction->rules()->find($ruleId);
                    if ($rule) {
                        $rule->update($ruleData);
                        $updatedRuleIds[] = $ruleId;
                    }
                } else {
                    // Create new rule
                    $rule = $recurring_transaction->rules()->create($ruleData);
                    $updatedRuleIds[] = $rule->id;
                }
            }
            
            // Delete rules that weren't updated
            $toDelete = array_diff($existingRuleIds, $updatedRuleIds);
            if (!empty($toDelete)) {
                $recurring_transaction->rules()->whereIn('id', $toDelete)->delete();
            }
        } else {
            // If not dynamic amount, delete all rules
            $recurring_transaction->rules()->delete();
        }

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