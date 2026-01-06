<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Models\PlaidTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\JsonResponse;

class PlaidTransactionController extends Controller
{
    /**
     * Create the controller instance.
     */
    public function __construct()
    {
        // Authorize all actions through budget ownership
        $this->middleware(function ($request, $next) {
            $budget = $request->route('budget');
            if ($budget) {
                $this->authorize('view', $budget);
            }
            return $next($request);
        });
    }

    /**
     * Display a listing of plaid transactions for an account.
     */
    public function index(Budget $budget, Account $account): Response
    {
        // Ensure the account belongs to the budget
        if ($account->budget_id !== $budget->id) {
            abort(404);
        }

        $this->authorize('view', $account);

        $plaidTransactions = PlaidTransaction::where('account_id', $account->id)
            ->orderByDesc('date')
            ->paginate(20);

        return Inertia::render('PlaidTransactions/Index', [
            'budget' => $budget,
            'account' => $account,
            'plaidTransactions' => $plaidTransactions,
        ]);
    }

    /**
     * API endpoint to get plaid transactions for an account.
     */
    public function getTransactions(Request $request, Budget $budget, Account $account): JsonResponse
    {
        // Ensure the account belongs to the budget
        if ($account->budget_id !== $budget->id) {
            abort(404);
        }

        $this->authorize('view', $account);

        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'pending' => 'nullable|boolean',
            'search' => 'nullable|string|max:255',
        ]);

        $query = PlaidTransaction::where('account_id', $account->id);

        // Apply filters
        if (isset($validated['start_date'])) {
            $query->where('date', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('date', '<=', $validated['end_date']);
        }

        if (isset($validated['pending'])) {
            $query->where('pending', $validated['pending']);
        }

        if (isset($validated['search'])) {
            $search = $validated['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('merchant_name', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        // Set default per_page if not provided
        $perPage = $validated['per_page'] ?? 20;

        $plaidTransactions = $query->orderByDesc('date')
            ->paginate($perPage);

        return response()->json($plaidTransactions);
    }

    /**
     * Get details of a specific plaid transaction.
     */
    public function show(Budget $budget, Account $account, string $plaidTransactionId): JsonResponse
    {
        // Ensure the account belongs to the budget
        if ($account->budget_id !== $budget->id) {
            return response()->json(['error' => 'Account not found in this budget'], 404);
        }

        $this->authorize('view', $account);

        $plaidTransaction = PlaidTransaction::where('account_id', $account->id)
            ->where('plaid_transaction_id', $plaidTransactionId)
            ->first();

        if (!$plaidTransaction) {
            return response()->json(['error' => 'Plaid transaction not found'], 404);
        }

        return response()->json($plaidTransaction);
    }
} 