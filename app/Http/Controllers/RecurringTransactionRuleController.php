<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Services\RecurringTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecurringTransactionRuleController extends Controller
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
     * Display rules for a recurring transaction template.
     */
    public function index(Budget $budget, RecurringTransactionTemplate $recurring_transaction): Response
    {
        $this->authorize('view', $budget);

        $rules = $recurring_transaction->rules()
            ->orderBy('priority')
            ->get();

        return Inertia::render('RecurringTransactions/Rules/Index', [
            'budget' => $budget,
            'recurringTransaction' => $recurring_transaction,
            'rules' => $rules,
            'fieldOptions' => RecurringTransactionRule::getFieldOptions(),
            'operatorOptions' => RecurringTransactionRule::getOperatorOptions(),
        ]);
    }

    /**
     * Store a newly created rule in storage.
     */
    public function store(Request $request, Budget $budget, RecurringTransactionTemplate $recurring_transaction): RedirectResponse
    {
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'field' => 'required|string|in:' . implode(',', array_keys(RecurringTransactionRule::getFieldOptions())),
            'operator' => 'required|string|in:' . implode(',', array_keys(RecurringTransactionRule::getOperatorOptions())),
            'value' => 'required|string|max:255',
            'is_case_sensitive' => 'boolean',
            'priority' => 'integer|min:1',
            'is_active' => 'boolean',
        ]);

        // Set default values
        $validated['is_case_sensitive'] = $validated['is_case_sensitive'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // If priority is not set, make it higher than the highest existing priority
        if (!isset($validated['priority'])) {
            $maxPriority = $recurring_transaction->rules()->max('priority') ?? 0;
            $validated['priority'] = $maxPriority + 1;
        }

        $recurring_transaction->rules()->create($validated);

        return redirect()->route('recurring-transactions.rules.index', ['budget' => $budget, 'recurring_transaction' => $recurring_transaction])
            ->with('message', 'Rule created successfully');
    }

    /**
     * Update the specified rule in storage.
     */
    public function update(Request $request, Budget $budget, RecurringTransactionTemplate $recurring_transaction, RecurringTransactionRule $rule): RedirectResponse
    {
        $this->authorize('update', $budget);

        // Ensure the rule belongs to the given template
        if ($rule->recurring_transaction_template_id !== $recurring_transaction->id) {
            abort(404);
        }

        $validated = $request->validate([
            'field' => 'required|string|in:' . implode(',', array_keys(RecurringTransactionRule::getFieldOptions())),
            'operator' => 'required|string|in:' . implode(',', array_keys(RecurringTransactionRule::getOperatorOptions())),
            'value' => 'required|string|max:255',
            'is_case_sensitive' => 'boolean',
            'priority' => 'integer|min:1',
            'is_active' => 'boolean',
        ]);

        $rule->update($validated);

        // Re-evaluate all transaction links based on updated rules
        $results = $this->recurringTransactionService->reevaluateTransactionLinks($recurring_transaction);

        $message = 'Rule updated successfully';
        if ($results['linked'] > 0 || $results['unlinked'] > 0) {
            $message .= sprintf(' (%d transactions linked, %d unlinked)', $results['linked'], $results['unlinked']);
        }

        return redirect()->route('recurring-transactions.edit', ['budget' => $budget, 'recurring_transaction' => $recurring_transaction])
            ->with('message', $message);
    }

    /**
     * Remove the specified rule from storage.
     */
    public function destroy(Budget $budget, RecurringTransactionTemplate $recurring_transaction, RecurringTransactionRule $rule): RedirectResponse
    {
        $this->authorize('update', $budget);

        // Ensure the rule belongs to the given template
        if ($rule->recurring_transaction_template_id !== $recurring_transaction->id) {
            abort(404);
        }

        $rule->delete();

        // Re-evaluate transaction links after deleting the rule
        $results = $this->recurringTransactionService->reevaluateTransactionLinks($recurring_transaction);

        $message = 'Rule deleted successfully';
        if ($results['unlinked'] > 0) {
            $message .= sprintf(' (%d transactions unlinked)', $results['unlinked']);
        }

        return redirect()->route('recurring-transactions.edit', ['budget' => $budget, 'recurring_transaction' => $recurring_transaction])
            ->with('message', $message);
    }

    /**
     * Test a rule against existing transactions.
     */
    public function test(Request $request, Budget $budget, RecurringTransactionTemplate $recurring_transaction): Response
    {
        $this->authorize('view', $budget);

        $validated = $request->validate([
            'field' => 'required|string|in:' . implode(',', array_keys(RecurringTransactionRule::getFieldOptions())),
            'operator' => 'required|string|in:' . implode(',', array_keys(RecurringTransactionRule::getOperatorOptions())),
            'value' => 'required|string|max:255',
            'is_case_sensitive' => 'boolean',
        ]);

        // Create a temporary rule for testing
        $testRule = new RecurringTransactionRule($validated);
        $testRule->is_active = true;

        // Get the account's recent transactions
        $account = $recurring_transaction->account;
        $transactions = Transaction::where('account_id', $account->id)
            ->latest('date')
            ->take(100)
            ->get();

        // Test against each transaction
        $matchingTransactions = $transactions->filter(function ($transaction) use ($testRule) {
            return $testRule->matchesTransaction($transaction);
        })->values();

        return Inertia::render('RecurringTransactions/Rules/Test', [
            'budget' => $budget,
            'recurringTransaction' => $recurring_transaction,
            'rule' => $validated,
            'matchingTransactions' => $matchingTransactions,
            'totalTested' => $transactions->count(),
        ]);
    }

    /**
     * Preview what transactions would be matched by active rules.
     */
    public function preview(Budget $budget, RecurringTransactionTemplate $recurring_transaction): Response
    {
        $this->authorize('view', $budget);

        $account = $recurring_transaction->account;
        $rules = $recurring_transaction->rules()
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        if ($rules->isEmpty()) {
            return Inertia::render('RecurringTransactions/Rules/Preview', [
                'budget' => $budget,
                'recurringTransaction' => $recurring_transaction,
                'matchingTransactions' => [],
                'hasActiveRules' => false,
            ]);
        }

        // Get unlinked transactions from the last 90 days
        $transactions = Transaction::where('account_id', $account->id)
            ->whereNull('recurring_transaction_template_id')
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->latest('date')
            ->get();

        $matchingTransactions = [];

        foreach ($transactions as $transaction) {
            // Transaction must match ALL rules (AND logic) to be considered a match
            $matchesAllRules = true;
            $matchedRules = [];
            
            foreach ($rules as $rule) {
                if ($rule->matchesTransaction($transaction)) {
                    $matchedRules[] = $rule;
                } else {
                    $matchesAllRules = false;
                    break; // Stop checking if any rule fails
                }
            }
            
            if ($matchesAllRules) {
                $matchingTransactions[] = [
                    'transaction' => $transaction,
                    'matched_by_rule' => $matchedRules[0] ?? null, // Keep backward compatibility
                    'matched_rules' => $matchedRules, // Also include all matched rules
                ];
            }
        }

        return Inertia::render('RecurringTransactions/Rules/Preview', [
            'budget' => $budget,
            'recurringTransaction' => $recurring_transaction,
            'matchingTransactions' => $matchingTransactions,
            'hasActiveRules' => true,
        ]);
    }

    /**
     * Apply all active rules to recent transactions.
     */
    public function apply(Budget $budget, RecurringTransactionTemplate $recurring_transaction): RedirectResponse
    {
        $this->authorize('update', $budget);

        $account = $recurring_transaction->account;
        $rules = $recurring_transaction->rules()
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        if ($rules->isEmpty()) {
            return redirect()->route('recurring-transactions.rules.index', ['budget' => $budget, 'recurring_transaction' => $recurring_transaction])
                ->with('warning', 'No active rules to apply');
        }

        // Get unlinked transactions from the last 90 days
        $transactions = Transaction::where('account_id', $account->id)
            ->whereNull('recurring_transaction_template_id')
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->get();

        $matchCount = 0;

        foreach ($transactions as $transaction) {
            // Transaction must match ALL rules (AND logic) to be linked
            $matchesAllRules = true;
            foreach ($rules as $rule) {
                if (!$rule->matchesTransaction($transaction)) {
                    $matchesAllRules = false;
                    break; // Stop checking if any rule fails
                }
            }
            
            if ($matchesAllRules) {
                $transaction->update([
                    'recurring_transaction_template_id' => $recurring_transaction->id
                ]);
                $matchCount++;
            }
        }

        $message = $matchCount > 0
            ? "Successfully linked {$matchCount} transactions to this recurring transaction."
            : "No matching transactions found.";

        return redirect()->route('recurring-transactions.rules.index', ['budget' => $budget, 'recurring_transaction' => $recurring_transaction])
            ->with('message', $message);
    }

    /**
     * Unlink a transaction from its recurring transaction template.
     */
    public function unlink(Request $request, Budget $budget, RecurringTransactionTemplate $recurring_transaction): RedirectResponse
    {
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
        ]);

        $transaction = Transaction::findOrFail($validated['transaction_id']);

        // Ensure the transaction belongs to this budget
        if ($transaction->budget_id !== $budget->id) {
            abort(403, 'Transaction does not belong to this budget');
        }

        // Ensure the transaction is currently linked to this recurring transaction
        if ($transaction->recurring_transaction_template_id !== $recurring_transaction->id) {
            abort(400, 'Transaction is not linked to this recurring transaction');
        }

        $transaction->update([
            'recurring_transaction_template_id' => null
        ]);

        return back()->with('message', 'Transaction unlinked successfully');
    }

    /**
     * Get all transactions linked to this recurring transaction template.
     */
    public function linked(Budget $budget, RecurringTransactionTemplate $recurring_transaction): Response
    {
        $this->authorize('view', $budget);

        $linkedTransactions = Transaction::where('recurring_transaction_template_id', $recurring_transaction->id)
            ->with('account')
            ->latest('date')
            ->paginate(50);

        return Inertia::render('RecurringTransactions/Rules/Linked', [
            'budget' => $budget,
            'recurringTransaction' => $recurring_transaction,
            'linkedTransactions' => $linkedTransactions,
        ]);
    }
} 