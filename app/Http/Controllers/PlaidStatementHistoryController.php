<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidStatementHistory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class PlaidStatementHistoryController extends Controller
{
    /**
     * Get statement history for a Plaid-linked credit card account.
     *
     * @param Budget $budget
     * @param Account $account
     * @return JsonResponse
     */
    public function index(Budget $budget, Account $account): JsonResponse
    {
        // Find the PlaidAccount
        $plaidAccount = PlaidAccount::where('account_id', $account->id)->first();

        if (!$plaidAccount) {
            return response()->json([
                'error' => 'Account is not linked to Plaid.'
            ], 404);
        }

        // Verify this is a credit card account
        if (!$plaidAccount->isCreditCard()) {
            return response()->json([
                'error' => 'Statement history is only available for credit card accounts.'
            ], 400);
        }

        // Get statement history for the last 12 months
        $twelveMonthsAgo = Carbon::now()->subMonths(12);

        $statementHistory = PlaidStatementHistory::where('plaid_account_id', $plaidAccount->id)
            ->where('statement_issue_date', '>=', $twelveMonthsAgo)
            ->orderBy('statement_issue_date', 'asc')
            ->get()
            ->map(function ($statement) {
                return [
                    'statement_issue_date' => $statement->statement_issue_date->format('Y-m-d'),
                    'statement_balance' => $statement->statement_balance_cents / 100,
                    'statement_balance_cents' => $statement->statement_balance_cents,
                    'payment_due_date' => $statement->payment_due_date?->format('Y-m-d'),
                    'minimum_payment' => $statement->minimum_payment_cents ? $statement->minimum_payment_cents / 100 : null,
                    'minimum_payment_cents' => $statement->minimum_payment_cents,
                    'apr_percentage' => $statement->apr_percentage,
                    'credit_utilization_percentage' => $statement->credit_utilization_percentage,
                ];
            });

        return response()->json([
            'account_id' => $account->id,
            'account_name' => $account->name,
            'credit_limit' => $plaidAccount->credit_limit_cents ? $plaidAccount->credit_limit_cents / 100 : null,
            'credit_limit_cents' => $plaidAccount->credit_limit_cents,
            'history' => $statementHistory,
        ]);
    }
}
