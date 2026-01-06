<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidConnection;
use App\Models\Transaction;
use App\Services\PlaidService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class AccountController extends Controller
{
    /**
     * Create the controller instance.
     */
    public function __construct()
    {
        // Authorize all resource actions for accounts
        $this->middleware(function ($request, $next) {
            $budget = $request->route('budget');
            if ($budget) {
                $this->authorize('view', $budget);
            }
            return $next($request);
        });
    }

    /**
     * Show the form for creating a new account for a budget.
     */
    public function create(Budget $budget)
    {
        // Get existing connections for this budget to show user what they already have
        $existingConnections = PlaidConnection::where('budget_id', $budget->id)
            ->where('status', PlaidConnection::STATUS_ACTIVE)
            ->with('plaidAccounts.account')
            ->get()
            ->map(function ($connection) {
                return [
                    'id' => $connection->id,
                    'institution_name' => $connection->institution_name,
                    'accounts_count' => $connection->getAccountCount(),
                    'last_sync_at' => $connection->last_sync_at,
                ];
            });

        $linkToken = null;
        try {
            $plaidService = app(PlaidService::class);
            $linkToken = $plaidService->createLinkToken($budget);
        } catch (\Exception $e) {
            \Log::error('Failed to create Plaid link token: ' . $e->getMessage());
        }

        return Inertia::render('Accounts/CreateOrImport', [
            'budget' => $budget,
            'linkToken' => $linkToken,
            'existingConnections' => $existingConnections,
        ]);
    }

    /**
     * Store a newly created account in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'current_balance' => 'required|numeric',
            'include_in_budget' => 'boolean',
        ]);

        $includeInBudget = $validated['include_in_budget'] ?? true;

        /** @var Account $account */
        $account = $budget->accounts()->create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'current_balance_cents' => $validated['current_balance'] * 100,
            'balance_updated_at' => now(),
            'include_in_budget' => $includeInBudget,
            // Auto-sync: excluded accounts are also excluded from total balance
            'exclude_from_total_balance' => !$includeInBudget,
        ]);

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Account created successfully');
    }

    /**
     * Show the form for editing the specified account.
     */
    public function edit(Budget $budget, Account $account)
    {
        // Verify account belongs to budget
        if ($account->budget_id !== $budget->id) {
            abort(404);
        }

        $this->authorize('update', $account);

        // Load the Plaid account and connection relationships
        $account->load('plaidAccount.plaidConnection');
        
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
        // Verify account belongs to budget
        if ($account->budget_id !== $budget->id) {
            abort(404);
        }

        $this->authorize('update', $account);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'include_in_budget' => 'boolean',
        ]);

        // Auto-sync: excluded accounts are also excluded from total balance
        if (isset($validated['include_in_budget'])) {
            $validated['exclude_from_total_balance'] = !$validated['include_in_budget'];
        }

        $account->update($validated);

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Account updated successfully');
    }

    /**
     * Remove the specified account from storage.
     */
    public function destroy(Budget $budget, Account $account): RedirectResponse
    {
        // Verify account belongs to budget
        if ($account->budget_id !== $budget->id) {
            abort(404);
        }

        $this->authorize('delete', $account);

        // Don't allow deletion if there are transactions
        if ($account->transactions()->count() > 0) {
            return back()->with('error', 'Cannot delete account with existing transactions.');
        }
        
        // Delete the account
        $account->delete();

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Account deleted successfully');
    }

    /**
     * Update autopay configuration for an account.
     */
    public function updateAutopay(Request $request, Budget $budget, Account $account): RedirectResponse
    {
        // Validate the account belongs to this budget
        if ($account->budget_id !== $budget->id) {
            abort(403);
        }

        // Validate account is a credit card
        if (!$account->isAutopayEligible()) {
            return redirect()->back()->with('error', 'Autopay is only available for credit card accounts with statement data.');
        }

        // Validate request
        $validated = $request->validate([
            'autopay_enabled' => 'required|boolean',
            'autopay_source_account_id' => 'nullable|exists:accounts,id',
            'autopay_amount_override_cents' => 'nullable|integer|min:0',
        ]);

        // Additional validation: source account must be specified if autopay enabled
        if ($validated['autopay_enabled'] && !$validated['autopay_source_account_id']) {
            return redirect()->back()->with('error', 'Please select a source account for autopay.');
        }

        // Validate source account belongs to same budget and is valid type
        if ($validated['autopay_source_account_id']) {
            $sourceAccount = Account::findOrFail($validated['autopay_source_account_id']);

            if ($sourceAccount->budget_id !== $budget->id) {
                return redirect()->back()->with('error', 'Source account must be in the same budget.');
            }

            if (!$sourceAccount->canBeAutopaySource()) {
                return redirect()->back()->with('error', 'Source account must be a checking or savings account.');
            }

            if ($sourceAccount->id === $account->id) {
                return redirect()->back()->with('error', 'Source account cannot be the same as the credit card.');
            }
        }

        // Update account
        $account->update([
            'autopay_enabled' => $validated['autopay_enabled'],
            'autopay_source_account_id' => $validated['autopay_enabled'] ? $validated['autopay_source_account_id'] : null,
            'autopay_amount_override_cents' => $validated['autopay_amount_override_cents'],
        ]);

        $message = $validated['autopay_enabled']
            ? 'Autopay enabled successfully. Statement balance will be automatically deducted from ' . $sourceAccount->name
            : 'Autopay disabled successfully.';

        return redirect()->back()->with('message', $message);
    }
} 