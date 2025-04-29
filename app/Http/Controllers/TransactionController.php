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
            ->with('account')
            ->orderByDesc('date')
            ->paginate(20);

        return Inertia::render('Transactions/Index', [
            'budget' => $budget,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Show the form for creating a new transaction.
     */
    public function create(Budget $budget): Response
    {
        $accounts = $budget->accounts()->get();
        $recurringTemplates = $budget->recurringTransactionTemplates()
            ->with('account')
            ->get();

        return Inertia::render('Transactions/Create', [
            'budget' => $budget,
            'accounts' => $accounts,
            'recurringTemplates' => $recurringTemplates,
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

        // Update account balance
        /** @var Account $account */
        $account = Account::find($validated['account_id']);
        $account->update([
            'current_balance_cents' => $account->current_balance_cents + $amountInCents,
            'balance_updated_at' => now(),
        ]);

        return redirect()->route('budget.transaction.index', $budget)
            ->with('message', 'Transaction created successfully');
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

        // Calculate the difference in amount to update account balance
        $newAmountInCents = $validated['amount'] * 100;
        $amountDifference = $newAmountInCents - $transaction->amount_in_cents;

        // Update transaction
        $transaction->update([
            'account_id' => $validated['account_id'],
            'description' => $validated['description'],
            'amount_in_cents' => $newAmountInCents,
            'date' => $validated['date'],
            'category' => $validated['category'],
            'notes' => $validated['notes'] ?? null,
            'recurring_transaction_template_id' => $validated['recurring_transaction_template_id'] ?? null,
        ]);

        // If account has changed or amount has changed, update account balances
        if ($amountDifference !== 0 || $transaction->account_id !== $validated['account_id']) {
            // If account has changed, update both accounts
            if ($transaction->account_id !== $validated['account_id']) {
                // Update old account (decrease balance)
                /** @var Account $oldAccount */
                $oldAccount = Account::find($transaction->getOriginal('account_id'));
                $oldAccount->update([
                    'current_balance_cents' => $oldAccount->current_balance_cents - $transaction->getOriginal('amount_in_cents'),
                    'balance_updated_at' => now(),
                ]);

                // Update new account (increase balance)
                /** @var Account $newAccount */
                $newAccount = Account::find($validated['account_id']);
                $newAccount->update([
                    'current_balance_cents' => $newAccount->current_balance_cents + $newAmountInCents,
                    'balance_updated_at' => now(),
                ]);
            } else {
                // Just update the current account balance by the difference
                /** @var Account $account */
                $account = Account::find($validated['account_id']);
                $account->update([
                    'current_balance_cents' => $account->current_balance_cents + $amountDifference,
                    'balance_updated_at' => now(),
                ]);
            }
        }

        return redirect()->route('budget.transaction.index', $budget)
            ->with('message', 'Transaction updated successfully');
    }

    /**
     * Remove the specified transaction from storage.
     */
    public function destroy(Budget $budget, Transaction $transaction): RedirectResponse
    {
        // Update account balance
        /** @var Account $account */
        $account = Account::find($transaction->account_id);
        $account->update([
            'current_balance_cents' => $account->current_balance_cents - $transaction->amount_in_cents,
            'balance_updated_at' => now(),
        ]);

        // Delete the transaction
        $transaction->delete();

        return redirect()->route('budget.transaction.index', $budget)
            ->with('message', 'Transaction deleted successfully');
    }
}
