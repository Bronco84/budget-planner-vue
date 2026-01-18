<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Services\RecurringTransactionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
    public function create(Request $request, Budget $budget): Response
    {
        $this->authorize('update', $budget);

        $sourceTransaction = null;

        // If creating from an existing transaction, fetch its data
        if ($request->has('from_transaction')) {
            $sourceTransaction = \App\Models\Transaction::where('id', $request->input('from_transaction'))
                ->where('budget_id', $budget->id)
                ->first();
        }

        // Get credit cards eligible for autopay linking (have active autopay configured)
        $eligibleCreditCards = $this->getAutopayEligibleCreditCards($budget);

        return Inertia::render('RecurringTransactions/Create', [
            'budget' => $budget,
            'accounts' => $budget->accounts,
            'sourceTransaction' => $sourceTransaction,
            'eligibleCreditCards' => $eligibleCreditCards,
        ]);
    }

    /**
     * Store a newly created recurring transaction in storage.
     * @throws AuthorizationException
     */
    public function store(Request $request, Budget $budget): RedirectResponse
    {
        \Log::debug('RecurringTransaction store - request data:', $request->all());

        try {
            $this->authorize('update', $budget);
        } catch (\Exception $e) {
            \Log::error('Authorization failed for recurring transaction store:', [
                'user_id' => auth()->id(),
                'budget_id' => $budget->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        \Log::debug('About to validate store request:', [
            'request_keys' => array_keys($request->all()),
            'has_account_id' => $request->has('account_id'),
            'account_id_value' => $request->get('account_id'),
            'budget_id' => $budget->id
        ]);

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'nullable|numeric|required_if:is_dynamic_amount,false',
            'account_id' => 'required|exists:accounts,id',
            'linked_credit_card_account_id' => 'nullable|exists:accounts,id',
            'category' => 'required|string|max:255',
            'frequency' => 'required|string|in:daily,weekly,biweekly,monthly,bimonthly,quarterly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'day_of_week' => 'nullable|integer|min:0|max:6|required_if:frequency,weekly,biweekly',
            'day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,monthly,quarterly,bimonthly',
            'first_day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,bimonthly|different:day_of_month',
            'is_dynamic_amount' => 'required|boolean',
            'min_amount' => 'nullable|numeric',
            'max_amount' => 'nullable|numeric',
            'average_amount' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'rules' => 'array',
            'rules.*.field' => 'required|string|in:description,amount,category',
            'rules.*.operator' => 'required|string|in:contains,equals,starts_with,ends_with,regex,greater_than,less_than',
            'rules.*.value' => 'required|string|max:255',
            'rules.*.is_case_sensitive' => 'boolean',
        ]);

        // Additional validation for bimonthly frequency
        if ($validated['frequency'] === 'bimonthly') {
            // Check if both fields are present and equal
            if (isset($validated['first_day_of_month']) && isset($validated['day_of_month']) 
                && $validated['first_day_of_month'] === $validated['day_of_month']) {
                return back()->withErrors([
                    'first_day_of_month' => 'The first day of month must be different from the day of month for bimonthly frequency.',
                ]);
            }
            
            // Ensure first_day_of_month is present for bimonthly
            if (!isset($validated['first_day_of_month'])) {
                return back()->withErrors([
                    'first_day_of_month' => 'The first day of month is required for bimonthly frequency.',
                ]);
            }
        }

        // Convert amount to cents
        $validated = $this->getArr($validated);

        // Convert is_dynamic_amount to boolean (handle both string and boolean inputs)
        $validated['is_dynamic_amount'] = filter_var($validated['is_dynamic_amount'], FILTER_VALIDATE_BOOLEAN);

        // Extract rules from validated data
        $rules = $validated['rules'] ?? [];
        unset($validated['rules']);
        
        // Ensure budget_id is set
        $validated['budget_id'] = $budget->id;

        // Create the recurring transaction
        try {
            $recurringTransaction = $budget->recurringTransactionTemplates()->create($validated);
            
            \Log::debug('RecurringTransaction created:', [
                'id' => $recurringTransaction->id,
                'validated_data' => $validated
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to create recurring transaction:', [
                'validated_data' => $validated,
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // Create rules if provided (rules can be used for both dynamic and fixed amount templates)
        if (!empty($rules)) {
            foreach ($rules as $index => $ruleData) {
                // Add priority based on the order of rules
                $ruleData['priority'] = $index + 1;
                $recurringTransaction->rules()->create($ruleData);
            }
            
            // Link existing transactions that match the rules
            $recurringService = app(RecurringTransactionService::class);
            $linkedCount = $recurringService->linkMatchingTransactionsByRules($recurringTransaction);
            
            \Log::debug('Linked matching transactions by rules:', [
                'template_id' => $recurringTransaction->id,
                'linked_count' => $linkedCount,
                'is_dynamic_amount' => $validated['is_dynamic_amount']
            ]);
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

        // Load linked transactions
        $linkedTransactions = $recurring_transaction->transactions()
            ->with('account')
            ->orderBy('date', 'desc')
            ->get();

        // Get credit cards eligible for autopay linking (have active autopay configured)
        $eligibleCreditCards = $this->getAutopayEligibleCreditCards($budget);

        return Inertia::render('RecurringTransactions/Show', [
            'budget' => $budget,
            'accounts' => $budget->accounts,
            'recurringTransaction' => $recurring_transaction->load(['account', 'linkedCreditCard']),
            'rules' => $rules,
            'linkedTransactions' => $linkedTransactions,
            'fieldOptions' => \App\Models\RecurringTransactionRule::getFieldOptions(),
            'operatorOptions' => \App\Models\RecurringTransactionRule::getOperatorOptions(),
            'eligibleCreditCards' => $eligibleCreditCards,
        ]);
    }

    /**
     * Update the specified recurring transaction in storage.
     */
    public function update(Request $request, Budget $budget, RecurringTransactionTemplate $recurring_transaction): RedirectResponse
    {
        $this->authorize('update', $budget);

        // Log raw request data for debugging
        Log::debug('Updating recurring transaction - raw request data:', [
            'request_all' => $request->all(),
            'rules_data' => $request->input('rules', []),
        ]);

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'nullable|numeric|required_if:is_dynamic_amount,false',
            'account_id' => 'required|exists:accounts,id',
            'linked_credit_card_account_id' => 'nullable|exists:accounts,id',
            'category' => 'required|string|max:255',
            'frequency' => 'required|string|in:daily,weekly,biweekly,monthly,bimonthly,quarterly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'day_of_week' => 'nullable|integer|min:0|max:6|required_if:frequency,weekly,biweekly',
            'day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,monthly,quarterly,bimonthly',
            'first_day_of_month' => 'nullable|integer|min:1|max:31|required_if:frequency,bimonthly|different:day_of_month',
            'is_dynamic_amount' => 'required|boolean',
            'min_amount' => 'nullable|numeric',
            'max_amount' => 'nullable|numeric',
            'average_amount' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'rules' => 'array',
            'rules.*.id' => 'nullable|integer|exists:recurring_transaction_rules,id',
            'rules.*.field' => 'required|string|in:description,amount,category',
            'rules.*.operator' => 'required|string|in:contains,equals,starts_with,ends_with,regex,greater_than,less_than',
            'rules.*.value' => 'required|string|max:255',
            'rules.*.is_case_sensitive' => 'boolean',
        ]);

        // Additional validation for bimonthly frequency
        if ($validated['frequency'] === 'bimonthly') {
            // Check if both fields are present and equal
            if (isset($validated['first_day_of_month']) && isset($validated['day_of_month']) 
                && $validated['first_day_of_month'] === $validated['day_of_month']) {
                return back()->withErrors([
                    'first_day_of_month' => 'The first day of month must be different from the day of month for bimonthly frequency.',
                ]);
            }
            
            // Ensure first_day_of_month is present for bimonthly
            if (!isset($validated['first_day_of_month'])) {
                return back()->withErrors([
                    'first_day_of_month' => 'The first day of month is required for bimonthly frequency.',
                ]);
            }
        }

        // Log validated data for debugging
        Log::debug('Validated recurring transaction data:', [
            'is_dynamic_amount' => $validated['is_dynamic_amount'],
            'amount' => $validated['amount'] ?? null,
            'rules' => $validated['rules'] ?? [],
        ]);

        // Convert amount to cents
        $validated = $this->getArr($validated);

        // Log after conversion
        Log::debug('After getArr conversion:', [
            'amount_in_cents' => $validated['amount_in_cents'] ?? null,
            'all_validated' => $validated,
        ]);

        // Extract rules from validated data
        $rules = $validated['rules'] ?? [];
        unset($validated['rules']);

        // Update the recurring transaction
        $recurring_transaction->update($validated);

        // Log after update
        Log::debug('After update:', [
            'id' => $recurring_transaction->id,
            'amount_in_cents' => $recurring_transaction->amount_in_cents,
            'is_dynamic_amount' => $recurring_transaction->is_dynamic_amount,
        ]);

        // Handle rules (rules can be used for both dynamic and fixed amount templates)
        // Get existing rule IDs to determine which ones to delete
        $existingRuleIds = $recurring_transaction->rules()->pluck('id')->toArray();
        $updatedRuleIds = [];

        Log::debug('Processing recurring transaction rules:', [
            'existing_rule_ids' => $existingRuleIds,
            'rules_count' => count($rules),
            'is_dynamic_amount' => $request->input('is_dynamic_amount'),
        ]);

        // Update or create rules
        foreach ($rules as $ruleData) {
            $ruleId = $ruleData['id'] ?? null;

            // Remove id from the data before create/update
            if (isset($ruleData['id'])) {
                unset($ruleData['id']);
            }

            Log::debug('Processing rule:', [
                'rule_id' => $ruleId,
                'rule_data' => $ruleData,
            ]);

            if ($ruleId) {
                // Update existing rule if it belongs to this template
                $rule = $recurring_transaction->rules()->find($ruleId);
                if ($rule) {
                    $rule->update($ruleData);
                    $updatedRuleIds[] = $ruleId;
                    Log::debug('Updated existing rule:', ['rule_id' => $ruleId]);
                }
            } else {
                // Create new rule
                $rule = $recurring_transaction->rules()->create($ruleData);
                $updatedRuleIds[] = $rule->id;
                Log::debug('Created new rule:', ['rule_id' => $rule->id]);
            }
        }

        // Delete rules that weren't in the update (removed by user)
        $toDelete = array_diff($existingRuleIds, $updatedRuleIds);
        if (!empty($toDelete)) {
            $recurring_transaction->rules()->whereIn('id', $toDelete)->delete();
            Log::debug('Deleted rules:', ['deleted_rule_ids' => $toDelete]);
        }

        // Verify rules were processed
        $finalRules = $recurring_transaction->rules()->get();
        Log::debug('Final rules after update:', [
            'count' => $finalRules->count(),
            'rules' => $finalRules,
        ]);

        return redirect()->route('recurring-transactions.index', $budget)
            ->with('message', 'Recurring transaction updated successfully');
    }

    /**r
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

    /**
     * @param array $validated
     * @return array
     */
    public function getArr(array $validated): array
    {
        Log::debug('getArr - Input:', ['validated' => $validated]);
        
        // Handle amount conversion - set to 0 for dynamic amount transactions
        if (isset($validated['amount']) && $validated['amount'] !== null) {
            $validated['amount_in_cents'] = (int)($validated['amount'] * 100);
            Log::debug('getArr - Converted amount:', [
                'original' => $validated['amount'],
                'converted' => $validated['amount_in_cents']
            ]);
        } else {
            $validated['amount_in_cents'] = 0; // Default for dynamic amount transactions
            Log::debug('getArr - No amount provided, setting to 0');
        }
        
        // Determine if this is an expense (negative) or income (positive)
        $isExpense = ($validated['amount_in_cents'] ?? 0) < 0;
        
        unset($validated['amount']);

        // Convert dynamic amount values to cents if provided
        // Ensure min/max have the same sign as the main amount
        if (isset($validated['min_amount']) && $validated['min_amount'] !== null) {
            $minCents = (int)(abs($validated['min_amount']) * 100);
            $validated['min_amount'] = $isExpense ? -$minCents : $minCents;
        }

        if (isset($validated['max_amount']) && $validated['max_amount'] !== null) {
            $maxCents = (int)(abs($validated['max_amount']) * 100);
            $validated['max_amount'] = $isExpense ? -$maxCents : $maxCents;
        }
        
        Log::debug('getArr - Output:', ['validated' => $validated]);
        
        return $validated;
    }

    /**
     * Get credit cards in the budget that have active autopay configured.
     *
     * @param Budget $budget
     * @return \Illuminate\Support\Collection
     */
    protected function getAutopayEligibleCreditCards(Budget $budget)
    {
        return $budget->accounts()
            ->with('plaidAccount')
            ->where('autopay_enabled', true)
            ->whereNotNull('autopay_source_account_id')
            ->get()
            ->filter(function ($account) {
                return $account->hasActiveAutopay();
            })
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'institution_name' => $account->plaidAccount?->institution_name,
                    'account_mask' => $account->plaidAccount?->account_mask,
                    'next_payment_due_date' => $account->getNextAutopayDate()?->format('Y-m-d'),
                    'statement_balance_cents' => $account->plaidAccount?->last_statement_balance_cents,
                ];
            })
            ->values();
    }
    
    /**
     * Get matching diagnostics for a recurring transaction template.
     * Useful for debugging and understanding how transactions are matched.
     */
    public function diagnostics(Budget $budget, RecurringTransactionTemplate $recurring_transaction)
    {
        $this->authorize('view', $budget);
        
        $diagnostics = $this->recurringTransactionService->getMatchingDiagnostics($recurring_transaction);
        
        return response()->json($diagnostics);
    }

    /**
     * Test matching for a recurring transaction template.
     * Shows which recent unlinked transactions would match with current settings.
     */
    public function testMatching(Budget $budget, RecurringTransactionTemplate $recurring_transaction)
    {
        $this->authorize('view', $budget);
        
        $account = $recurring_transaction->account;
        $rules = $recurring_transaction->rules()->where('is_active', true)->get();
        
        // Get recent unlinked transactions (last 90 days)
        $recentTransactions = Transaction::where('account_id', $account->id)
            ->whereNull('recurring_transaction_template_id')
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->orderBy('date', 'desc')
            ->with('plaidTransaction')
            ->get();
        
        $matches = [];
        
        foreach ($recentTransactions as $transaction) {
            $matchMethod = null;
            $matchDetails = null;
            
            // Test entity ID matching
            if ($recurring_transaction->plaid_entity_id && $transaction->plaidTransaction) {
                $counterparties = $transaction->plaidTransaction->counterparties ?? '';
                if (is_string($counterparties) && str_contains($counterparties, $recurring_transaction->plaid_entity_id)) {
                    $matchMethod = 'entity_id';
                    $matchDetails = 'Matched by Plaid entity ID: ' . $recurring_transaction->plaid_entity_name;
                }
            }
            
            // Test rules matching (only if no entity match)
            if (!$matchMethod && $rules->isNotEmpty()) {
                $matchesAllRules = true;
                
                foreach ($rules as $rule) {
                    if (!$rule->matchesTransaction($transaction)) {
                        $matchesAllRules = false;
                        break;
                    }
                }
                
                if ($matchesAllRules) {
                    $matchMethod = 'rules';
                    $matchDetails = 'Matched all ' . $rules->count() . ' active rules';
                }
            }
            
            // Test description matching (only if no entity or rules match)
            if (!$matchMethod && $recurring_transaction->description) {
                // Use the improved matching algorithm
                $templateDesc = strtolower(trim($recurring_transaction->description));
                $transactionDesc = strtolower(trim($transaction->description));
                
                if ($transactionDesc === $templateDesc) {
                    $matchMethod = 'description_exact';
                    $matchDetails = 'Exact description match';
                } elseif (strlen($templateDesc) >= 5 && str_contains($transactionDesc, $templateDesc)) {
                    if (!$recurring_transaction->category || $transaction->category === $recurring_transaction->category) {
                        $matchMethod = 'description_contains';
                        $matchDetails = 'Description contains "' . $recurring_transaction->description . '"';
                        if ($recurring_transaction->category) {
                            $matchDetails .= ' and category matches';
                        }
                    }
                } else {
                    similar_text($templateDesc, $transactionDesc, $percent);
                    if ($percent >= 70) {
                        if (!$recurring_transaction->category || $transaction->category === $recurring_transaction->category) {
                            $matchMethod = 'description_fuzzy';
                            $matchDetails = 'Fuzzy match (' . round($percent) . '% similarity)';
                            if ($recurring_transaction->category) {
                                $matchDetails .= ' and category matches';
                            }
                        }
                    }
                }
            }
            
            if ($matchMethod) {
                $matches[] = [
                    'transaction' => [
                        'id' => $transaction->id,
                        'date' => $transaction->date->format('Y-m-d'),
                        'description' => $transaction->description,
                        'category' => $transaction->category,
                        'amount' => $transaction->amount_in_cents / 100,
                    ],
                    'match_method' => $matchMethod,
                    'match_details' => $matchDetails,
                ];
            }
        }
        
        return response()->json([
            'total_tested' => $recentTransactions->count(),
            'matches_found' => count($matches),
            'matches' => $matches,
            'template' => [
                'description' => $recurring_transaction->description,
                'category' => $recurring_transaction->category,
                'has_entity_id' => !empty($recurring_transaction->plaid_entity_id),
                'entity_name' => $recurring_transaction->plaid_entity_name,
                'active_rules_count' => $rules->count(),
            ],
        ]);
    }
}
