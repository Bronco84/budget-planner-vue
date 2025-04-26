<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class AccountController extends Controller
{
    /**
     * Show the form for creating a new account for a budget.
     */
    public function create(Budget $budget)
    {
        return Inertia::render('Accounts/Create', [
            'budget' => $budget
        ]);
    }

    /**
     * Store a newly created account in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:checking,savings,credit,investment,other',
            'current_balance' => 'required|numeric',
            'include_in_budget' => 'boolean',
        ]);

        /** @var Account $account */
        $account = $budget->accounts()->create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'current_balance_cents' => $validated['current_balance'] * 100,
            'balance_updated_at' => now(),
            'include_in_budget' => $validated['include_in_budget'] ?? true,
        ]);

        // Create an initial transaction for this balance
        $account->transactions()->create([
            'budget_id' => $budget->id,
            'description' => 'Initial Balance',
            'amount_in_cents' => $validated['current_balance'] * 100,
            'date' => now(),
            'category' => 'Starting Balance',
            'is_reconciled' => true,
        ]);

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Account created successfully');
    }

    /**
     * Show the form for editing the specified account.
     */
    public function edit(Budget $budget, Account $account)
    {
        return Inertia::render('Accounts/Edit', [
            'budget' => $budget,
            'account' => $account
        ]);
    }

    /**
     * Update the specified account in storage.
     */
    public function update(Request $request, Budget $budget, Account $account): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:checking,savings,credit,investment,other',
            'include_in_budget' => 'boolean',
        ]);

        $account->update($validated);

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Account updated successfully');
    }

    /**
     * Remove the specified account from storage.
     */
    public function destroy(Budget $budget, Account $account): RedirectResponse
    {
        // Don't allow deletion if there are transactions
        if ($account->transactions()->count() > 1) { // More than the initial balance transaction
            return back()->with('error', 'Cannot delete account with existing transactions.');
        }

        // Delete the initial transaction
        $account->transactions()->delete();
        
        // Delete the account
        $account->delete();

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Account deleted successfully');
    }
} 