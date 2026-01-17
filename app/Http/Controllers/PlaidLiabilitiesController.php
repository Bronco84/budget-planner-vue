<?php

namespace App\Http\Controllers;

use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Services\PlaidService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PlaidLiabilitiesController extends Controller
{
    protected PlaidService $plaidService;

    /**
     * Create a new controller instance.
     */
    public function __construct(PlaidService $plaidService)
    {
        $this->plaidService = $plaidService;
    }

    /**
     * Display the admin page for managing Plaid liabilities.
     */
    public function index(): Response
    {
        $user = Auth::user();

        // Get all Plaid connections for the user's budgets with their credit card accounts
        $connections = PlaidConnection::whereHas('budget', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->with(['plaidAccounts' => function ($query) {
                $query->where('account_type', 'credit')
                    ->where('account_subtype', 'credit card');
            }, 'budget'])
            ->get()
            ->filter(function ($connection) {
                // Only include connections that have credit card accounts
                return $connection->plaidAccounts->isNotEmpty();
            })
            ->map(function ($connection) {
                return [
                    'id' => $connection->id,
                    'institution_name' => $connection->institution_name,
                    'institution_logo' => $connection->institution_logo,
                    'status' => $connection->status,
                    'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
                    'budget_name' => $connection->budget->name,
                    'credit_cards' => $connection->plaidAccounts->map(function ($account) {
                        return [
                            'id' => $account->id,
                            'account_name' => $account->account_name,
                            'account_mask' => $account->account_mask,
                            'current_balance_cents' => $account->current_balance_cents,
                            'last_statement_balance_cents' => $account->last_statement_balance_cents,
                            'last_statement_issue_date' => $account->last_statement_issue_date?->toDateString(),
                            'next_payment_due_date' => $account->next_payment_due_date?->toDateString(),
                            'minimum_payment_amount_cents' => $account->minimum_payment_amount_cents,
                            'credit_limit_cents' => $account->credit_limit_cents,
                            'apr_percentage' => $account->apr_percentage,
                            'liability_updated_at' => $account->liability_updated_at?->toIso8601String(),
                        ];
                    })->values(),
                ];
            })
            ->values();

        return Inertia::render('Admin/PlaidLiabilities', [
            'connections' => $connections,
        ]);
    }

    /**
     * Update liability data for a specific Plaid connection.
     */
    public function updateConnection(PlaidConnection $connection): RedirectResponse
    {
        $user = Auth::user();

        // Verify the connection belongs to the user
        if ($connection->budget->user_id !== $user->id) {
            return redirect()->back()->with('error', 'Unauthorized access to this connection.');
        }

        // Get all credit card accounts for this connection
        $creditCards = $connection->plaidAccounts()
            ->where('account_type', 'credit')
            ->where('account_subtype', 'credit card')
            ->get();

        if ($creditCards->isEmpty()) {
            return redirect()->back()->with('error', 'No credit card accounts found for this connection.');
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($creditCards as $creditCard) {
            try {
                $updated = $this->plaidService->updateLiabilityData($creditCard);
                if ($updated) {
                    $successCount++;
                    // The updateLiabilityData method updates all cards from the connection,
                    // so we can break after the first success
                    break;
                } else {
                    $failCount++;
                }
            } catch (\Exception $e) {
                Log::error('Failed to update liability data for credit card', [
                    'plaid_account_id' => $creditCard->id,
                    'error' => $e->getMessage(),
                ]);
                $failCount++;
            }
        }

        if ($successCount > 0) {
            return redirect()->back()->with('message', "Successfully updated liability data for {$connection->institution_name}.");
        }

        return redirect()->back()->with('error', "Failed to update liability data for {$connection->institution_name}. The institution may not support this feature.");
    }

    /**
     * Update liability data for all Plaid connections with credit cards.
     */
    public function updateAll(): RedirectResponse
    {
        $user = Auth::user();

        // Get all Plaid connections for the user's budgets that have credit card accounts
        $connections = PlaidConnection::whereHas('budget', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->whereHas('plaidAccounts', function ($query) {
                $query->where('account_type', 'credit')
                    ->where('account_subtype', 'credit card');
            })
            ->with(['plaidAccounts' => function ($query) {
                $query->where('account_type', 'credit')
                    ->where('account_subtype', 'credit card');
            }])
            ->get();

        if ($connections->isEmpty()) {
            return redirect()->back()->with('error', 'No credit card accounts found to update.');
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($connections as $connection) {
            // Get the first credit card from each connection to trigger the update
            // (updateLiabilityData updates all cards from the same connection)
            $creditCard = $connection->plaidAccounts->first();

            if (!$creditCard) {
                continue;
            }

            try {
                $updated = $this->plaidService->updateLiabilityData($creditCard);
                if ($updated) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Exception $e) {
                Log::error('Failed to update liability data for connection', [
                    'connection_id' => $connection->id,
                    'institution' => $connection->institution_name,
                    'error' => $e->getMessage(),
                ]);
                $failCount++;
            }
        }

        if ($successCount > 0 && $failCount === 0) {
            return redirect()->back()->with('message', "Successfully updated liability data for {$successCount} connection(s).");
        } elseif ($successCount > 0) {
            return redirect()->back()->with('message', "Updated {$successCount} connection(s), but {$failCount} failed. Some institutions may not support this feature.");
        }

        return redirect()->back()->with('error', 'Failed to update liability data. The institutions may not support this feature.');
    }
}
