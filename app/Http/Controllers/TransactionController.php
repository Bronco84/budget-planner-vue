<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    /**
     * Display a listing of the transactions for a budget.
     */
    public function index(Budget $budget): Response
    {
        $transactions = $budget->transactions()
            ->with(['account', 'recurringTemplate'])
            ->orderByDesc('date')
            ->paginate(20);

        $accounts = $budget->accounts()->get();
        $categories = $budget->transactions()
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->pluck('category');

        return Inertia::render('Transactions/Index', [
            'budget' => $budget,
            'transactions' => $transactions,
            'accounts' => $accounts,
            'categories' => $categories,
        ]);
    }

    /**
     * Show the form for creating a new transaction.
     */
    public function create(Request $request, Budget $budget): Response
    {
        $accounts = $budget->accounts()->get();
        $recurringTemplates = $budget->recurringTransactionTemplates()
            ->with('account')
            ->get();

        // Get pre-fill data from query parameters
        $prefillData = [
            'description' => $request->query('description', ''),
            'account_id' => $request->query('account_id', ''),
            'category' => $request->query('category', ''),
            'date' => $request->query('date', ''),
            'amount' => $request->query('amount', ''),
            'recurring_transaction_template_id' => $request->query('recurring_transaction_template_id', ''),
        ];

        return Inertia::render('Transactions/Create', [
            'budget' => $budget,
            'accounts' => $accounts,
            'recurringTemplates' => $recurringTemplates,
            'prefillData' => $prefillData,
        ]);
    }

    /**
     * Store a newly created transaction in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'date' => 'required|date',
            'category' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'recurring_transaction_template_id' => 'nullable|exists:recurring_transaction_templates,id',
        ]);

        // Convert amount to cents and handle negative values for expenses
        $amountInCents = $validated['amount'] * 100;

        /** @var Transaction $transaction */
        $transaction = $budget->transactions()->create([
            'account_id' => $validated['account_id'],
            'description' => $validated['description'],
            'amount_in_cents' => $amountInCents,
            'date' => $validated['date'],
            'category' => $validated['category'],
            'notes' => $validated['notes'] ?? null,
            'recurring_transaction_template_id' => $validated['recurring_transaction_template_id'] ?? null,
        ]);

        return redirect()->route('budget.transaction.edit', [$budget, $transaction])
            ->with('message', 'Transaction created successfully! You can now add file attachments.');
    }

    /**
     * Show the form for editing the specified transaction.
     */
    public function edit(Budget $budget, Transaction $transaction): Response
    {
        $accounts = $budget->accounts()->get();
        $recurringTemplates = $budget->recurringTransactionTemplates()
            ->with('account')
            ->get();

        // Check if this is a recurring transaction
        $recurringTemplate = null;
        $rules = [];
        if ($transaction->recurring_transaction_template_id) {
            $recurringTemplate = $transaction->recurringTemplate;
            if ($recurringTemplate) {
                $rules = $recurringTemplate->rules()->get();
            }
        }

        return Inertia::render('Transactions/Edit', [
            'budget' => $budget,
            'transaction' => $transaction,
            'accounts' => $accounts,
            'recurringTemplates' => $recurringTemplates,
            'recurringTemplate' => $recurringTemplate,
            'rules' => $rules,
        ]);
    }

    /**
     * Update the specified transaction in storage.
     */
    public function update(Request $request, Budget $budget, Transaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'date' => 'required|date',
            'category' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'recurring_transaction_template_id' => 'nullable|exists:recurring_transaction_templates,id',
        ]);

        // Update transaction
        $transaction->update([
            'account_id' => $validated['account_id'],
            'description' => $validated['description'],
            'amount_in_cents' => $validated['amount'] * 100,
            'date' => $validated['date'],
            'category' => $validated['category'],
            'notes' => $validated['notes'] ?? null,
            'recurring_transaction_template_id' => $validated['recurring_transaction_template_id'] ?? null,
        ]);

        return redirect()->route('budget.transaction.index', $budget)
            ->with('message', 'Transaction updated successfully');
    }

    /**
     * Remove the specified transaction from storage.
     */
    public function destroy(Budget $budget, Transaction $transaction): RedirectResponse
    {
        $transaction->delete();

        return redirect()->route('budget.transaction.index', $budget)
            ->with('message', 'Transaction deleted successfully');
    }

    /**
     * Get activity log for a transaction.
     */
    public function getActivityLog(Budget $budget, Transaction $transaction)
    {
        $activityLog = $transaction->getActivityLogFormatted();

        return response()->json([
            'activities' => $activityLog
        ]);
    }
}
