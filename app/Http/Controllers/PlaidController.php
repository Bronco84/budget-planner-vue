<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Services\PlaidService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class PlaidController extends Controller
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
     * Show the form for linking a Plaid account.
     */
    public function showLinkForm(Budget $budget, Account $account): Response
    {
        // Check if the account is already linked to Plaid and load the connection relationship
        $plaidAccount = PlaidAccount::with('plaidConnection')
            ->where('account_id', $account->id)
            ->first();

        // Check for existing connections to facilitate adding more accounts
        $existingConnection = null;
        $hasExistingConnection = false;

        // If account is already linked, we can't use update mode (it's already connected)
        // But if account is NOT linked, check if there are existing connections to same institution
        if (!$plaidAccount) {
            // For simplicity, we'll check if there are ANY active connections for this budget
            // In a more advanced implementation, you could detect the institution name from the account
            $existingConnection = PlaidConnection::where('budget_id', $budget->id)
                ->where('status', PlaidConnection::STATUS_ACTIVE)
                ->first();

            $hasExistingConnection = $existingConnection !== null;
        }

        $linkToken = null;
        try {
            // Use update mode if there's an existing connection to add accounts to
            $existingAccessToken = $existingConnection?->access_token;
            $linkToken = $this->plaidService->createLinkToken(
                (string) Auth::id(),
                $existingAccessToken
            );

            if ($hasExistingConnection) {
                Log::info('Creating link token in update mode', [
                    'budget_id' => $budget->id,
                    'account_id' => $account->id,
                    'existing_connection_id' => $existingConnection->id,
                    'institution' => $existingConnection->institution_name
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to create link token', [
                'error' => $e->getMessage()
            ]);
        }

        return Inertia::render('Plaid/Link', [
            'budget' => $budget,
            'account' => $account,
            'linkToken' => $linkToken,
            'isLinked' => $plaidAccount !== null,
            'plaidAccount' => $plaidAccount,
            'hasExistingConnection' => $hasExistingConnection,
            'existingConnectionInstitution' => $existingConnection?->institution_name,
        ]);
    }
    
    /**
     * Discover all available accounts from Plaid for a budget.
     */
    public function discover(Budget $budget): Response
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
                    'account_count' => $connection->getAccountCount(),
                    'last_sync_at' => $connection->last_sync_at,
                    'accounts' => $connection->plaidAccounts->map(fn($pa) => [
                        'name' => $pa->account->name ?? $pa->account_name,
                        'type' => $pa->account->type ?? 'unknown',
                    ])
                ];
            });

        $linkToken = null;
        try {
            $linkToken = $this->plaidService->createLinkToken((string) Auth::id());
        } catch (\Exception $e) {
            Log::error('Failed to create link token for discovery', [
                'error' => $e->getMessage()
            ]);
        }

        return Inertia::render('Plaid/Discover', [
            'budget' => $budget,
            'linkToken' => $linkToken,
            'existingConnections' => $existingConnections,
        ]);
    }
    
    /**
     * Import multiple accounts from Plaid.
     */
    public function import(Request $request, Budget $budget): RedirectResponse
    {
        $validated = $request->validate([
            'public_token' => 'required|string',
            'metadata' => 'required|array',
            'metadata.institution' => 'required|array',
            'metadata.institution.name' => 'required|string',
            'selected_accounts' => 'required|array',
            'selected_accounts.*' => 'required|string',
        ]);
        
        try {
            // Exchange the public token for an access token
            $accessToken = $this->plaidService->exchangePublicToken($validated['public_token']);
            
            if (!$accessToken) {
                return redirect()->back()->with('error', 'Failed to connect to Plaid.');
            }
            
            // Get all accounts from Plaid
            $plaidAccounts = $this->plaidService->getAccounts($accessToken);
            $selectedPlaidAccounts = array_filter($plaidAccounts, function($account) use ($validated) {
                // Use 'id' field for Plaid metadata accounts, 'account_id' for API accounts
                $accountId = $account['id'] ?? $account['account_id'];
                return in_array($accountId, $validated['selected_accounts']);
            });
            
            $importedCount = 0;
            $itemId = $validated['metadata']['item']['id'] ??
                     $validated['metadata']['item_id'] ??
                     'plaid-item-' . uniqid();

            // Prepare account data for batch linking
            $accountData = [];
            foreach ($selectedPlaidAccounts as $plaidAccount) {
                // Create local account
                $accountType = $this->plaidService->mapPlaidAccountType($plaidAccount);
                $localAccount = $budget->accounts()->create([
                    'name' => $plaidAccount['name'],
                    'type' => $accountType,
                    'current_balance_cents' => isset($plaidAccount['balances']['current'])
                        ? (int) round($plaidAccount['balances']['current'] * 100)
                        : 0,
                    'balance_updated_at' => now(),
                    'include_in_budget' => true,
                ]);

                $accountData[] = [
                    'local_account' => $localAccount,
                    'plaid_account_data' => $plaidAccount
                ];

                $importedCount++;
            }

            // Fetch institution logo and URL if we have an institution ID
            $institutionId = $validated['metadata']['institution']['institution_id'] ?? null;
            $institutionLogo = null;
            $institutionUrl = null;
            if ($institutionId) {
                $institutionDetails = $this->plaidService->getInstitutionDetails($institutionId);
                $institutionLogo = $institutionDetails['logo'] ?? null;
                $institutionUrl = $institutionDetails['url'] ?? null;
            }

            // Link all accounts to Plaid under single connection
            $plaidAccounts = $this->plaidService->linkMultipleAccounts(
                $budget,
                $accountData,
                $accessToken,
                $itemId,
                $institutionId,
                $validated['metadata']['institution']['name'],
                $institutionLogo,
                $institutionUrl
            );

            // Sync transactions for all linked accounts
            foreach ($plaidAccounts as $plaidAccount) {
                $this->plaidService->syncTransactions($plaidAccount);
            }
            
            return redirect()->route('budgets.show', $budget)
                ->with('message', "Successfully imported {$importedCount} accounts from Plaid.");
                
        } catch (\Exception $e) {
            Log::error('Plaid import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()->with('error', 'Failed to import accounts: ' . $e->getMessage());
        }
    }

    /**
     * Store a new Plaid link.
     */
    public function store(Request $request, Budget $budget, Account $account): RedirectResponse
    {
        $validated = $request->validate([
            'public_token' => 'required|string',
            'metadata' => 'required|array',
            'metadata.institution' => 'required|array',
            'metadata.institution.name' => 'required|string',
            'metadata.account' => 'required|array',
            'metadata.account.id' => 'required|string',
        ]);
        
        try {
            // Exchange the public token for an access token
            $accessToken = $this->plaidService->exchangePublicToken($validated['public_token']);
            
            if (!$accessToken) {
                return redirect()->back()->with('error', 'Failed to connect to Plaid.');
            }
            
            // Extract itemId - use a default if not present in the expected location
            $itemId = $validated['metadata']['item']['id'] ?? 
                    $validated['metadata']['item_id'] ?? 
                    null;

            // Fetch institution logo and URL if we have an institution ID
            $institutionId = $validated['metadata']['institution']['institution_id'] ?? null;
            $institutionLogo = null;
            $institutionUrl = null;
            if ($institutionId) {
                $institutionDetails = $this->plaidService->getInstitutionDetails($institutionId);
                $institutionLogo = $institutionDetails['logo'] ?? null;
                $institutionUrl = $institutionDetails['url'] ?? null;
            }
                    
            // Link the account
            $plaidAccount = $this->plaidService->linkAccount(
                $budget,
                $account,
                $accessToken,
                $validated['metadata']['account']['id'],
                $itemId,
                $validated['metadata']['institution']['name'],
                $institutionId,
                $institutionLogo,
                $institutionUrl
            );
            
            // Sync transactions
            $this->plaidService->syncTransactions($plaidAccount);
            
            return redirect()->route('budgets.show', $budget)
                ->with('message', 'Account linked to Plaid successfully.');
        } catch (\Exception $e) {
            Log::error('Plaid linking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()->with('error', 'Failed to link account: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync transactions for a specific Plaid-linked account.
     */
    public function syncTransactions(Budget $budget, Account $account): RedirectResponse
    {
        $plaidAccount = PlaidAccount::where('account_id', $account->id)->first();

        if (!$plaidAccount) {
            return redirect()->back()->with('error', 'Account is not linked to Plaid.');
        }

        try {
            $result = $this->plaidService->syncTransactions($plaidAccount);

            return redirect()->back()->with('message',
                'Synced ' . $result['imported'] . ' new and updated ' . $result['updated'] . ' transactions.');
        } catch (\Exception $e) {
            // Handle PRODUCT_NOT_READY error specifically
            if (str_contains($e->getMessage(), 'PRODUCT_NOT_READY')) {
                return redirect()->back()->with('error',
                    'Transaction data for this account is still being processed by Plaid. This can take 1-3 days for credit cards after initial connection. Please try again later.');
            }

            Log::error('Single account sync error', [
                'account_id' => $account->id,
                'plaid_account_id' => $plaidAccount->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()->with('error', 'Failed to sync transactions: ' . $e->getMessage());
        }
    }
    
    /**
     * Update the account balance from Plaid.
     */
    public function updateBalance(Budget $budget, Account $account): RedirectResponse
    {
        $plaidAccount = PlaidAccount::where('account_id', $account->id)->first();

        if (!$plaidAccount) {
            return redirect()->back()->with('error', 'Account is not linked to Plaid.');
        }

        $updated = $this->plaidService->updateAccountBalance($plaidAccount);

        if ($updated) {
            return redirect()->back()->with('message', 'Account balance updated successfully.');
        }

        return redirect()->back()->with('error', 'Failed to update account balance.');
    }

    /**
     * Update liability data (statement balance, payment info) for a credit card account.
     */
    public function updateLiabilities(Budget $budget, Account $account): RedirectResponse
    {
        $plaidAccount = PlaidAccount::where('account_id', $account->id)->first();

        if (!$plaidAccount) {
            return redirect()->back()->with('error', 'Account is not linked to Plaid.');
        }

        // Verify this is a credit card account
        if (!$plaidAccount->isCreditCard()) {
            return redirect()->back()->with('error', 'Liability data is only available for credit card accounts.');
        }

        $updated = $this->plaidService->updateLiabilityData($plaidAccount);

        if ($updated) {
            return redirect()->back()->with('message', 'Statement balance and payment information updated successfully.');
        }

        return redirect()->back()->with('error', 'Failed to update statement data. This feature may not be available for all credit card providers.');
    }

    /**
     * Update investment data (holdings, securities) for an investment account.
     */
    public function updateInvestments(Budget $budget, Account $account): RedirectResponse
    {
        $plaidAccount = PlaidAccount::where('account_id', $account->id)->first();

        if (!$plaidAccount) {
            return redirect()->back()->with('error', 'Account is not linked to Plaid.');
        }

        // Verify this is an investment account
        if (!$plaidAccount->isInvestmentAccount()) {
            return redirect()->back()->with('error', 'Investment data is only available for investment accounts.');
        }

        $updated = $this->plaidService->updateInvestmentData($plaidAccount);

        if ($updated) {
            $holdingsCount = $plaidAccount->holdings()->count();
            return redirect()->back()->with('message', "Investment holdings updated successfully. {$holdingsCount} positions synced.");
        }

        return redirect()->back()->with('error', 'Failed to update investment data. This feature may not be available for all institutions.');
    }
    
    /**
     * Generate an upgrade link token for re-authenticating a Plaid connection.
     * Used when ITEM_LOGIN_REQUIRED error occurs.
     */
    public function upgradeLinkToken(Budget $budget, Account $account)
    {
        $plaidAccount = PlaidAccount::where('account_id', $account->id)->first();

        if (!$plaidAccount) {
            return response()->json(['error' => 'Account is not linked to Plaid.'], 404);
        }

        $accessToken = $plaidAccount->plaidConnection->access_token ?? null;

        if (!$accessToken) {
            return response()->json(['error' => 'No access token found for this connection.'], 400);
        }

        try {
            // Create a link token in update mode for re-authentication
            $linkToken = $this->plaidService->createLinkToken(
                (string) Auth::id(),
                $accessToken
            );

            return response()->json([
                'link_token' => $linkToken,
                'institution_name' => $plaidAccount->plaidConnection->institution_name,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create upgrade link token', [
                'error' => $e->getMessage(),
                'account_id' => $account->id,
                'plaid_account_id' => $plaidAccount->id,
            ]);

            return response()->json(['error' => 'Failed to create re-authentication link: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Unlink a Plaid account.
     */
    public function destroy(Budget $budget, Account $account): RedirectResponse
    {
        $plaidAccount = PlaidAccount::where('account_id', $account->id)->first();

        if (!$plaidAccount) {
            return redirect()->back()->with('error', 'Account is not linked to Plaid.');
        }

        // Get the connection before deleting the account
        $plaidConnection = $plaidAccount->plaidConnection;

        // Delete the PlaidAccount
        $plaidAccount->delete();

        // Check if the connection has no remaining accounts and clean it up
        if ($plaidConnection && !$plaidConnection->hasAccounts()) {
            Log::info('Cleaning up orphaned PlaidConnection', [
                'connection_id' => $plaidConnection->id,
                'institution_name' => $plaidConnection->institution_name,
                'budget_id' => $budget->id
            ]);

            $plaidConnection->delete();

            return redirect()->back()->with('message',
                'Account unlinked from Plaid. Connection to ' . $plaidConnection->institution_name . ' was also removed as it had no remaining accounts.');
        }

        return redirect()->back()->with('message', 'Account unlinked from Plaid.');
    }
    
    /**
     * Sync transactions for all Plaid-connected accounts in a budget.
     */
    public function syncAllTransactions(Budget $budget): RedirectResponse
    {
        // Log that the method has been called
        Log::info('syncAllTransactions method called', [
            'budget_id' => $budget->id,
            'budget_name' => $budget->name
        ]);
    
        // Find all accounts in this budget that have Plaid connections
        $plaidAccounts = PlaidAccount::whereHas('account', function ($query) use ($budget) {
            $query->where('budget_id', $budget->id);
        })->get();
        
        Log::info('Found Plaid accounts', [
            'count' => $plaidAccounts->count(),
            'accounts' => $plaidAccounts->map(function ($pa) {
                return [
                    'id' => $pa->id,
                    'plaid_account_id' => $pa->plaid_account_id,
                    'access_token' => $pa->access_token ? '[exists]' : '[null]',
                    'account_id' => $pa->account_id,
                    'institution_name' => $pa->institution_name,
                ];
            })
        ]);
        
        if ($plaidAccounts->isEmpty()) {
            Log::warning('No Plaid-connected accounts found for budget', ['budget_id' => $budget->id]);
            return redirect()->back()->with('error', 'No Plaid-connected accounts found.');
        }
        
        $totalImported = 0;
        $totalUpdated = 0;
        $errors = [];
        
        foreach ($plaidAccounts as $plaidAccount) {
            try {
                Log::info('Starting sync for account', [
                    'plaid_account_id' => $plaidAccount->id,
                    'account_id' => $plaidAccount->account_id,
                    'institution' => $plaidAccount->institution_name
                ]);

                $result = $this->plaidService->syncTransactions($plaidAccount);
                $totalImported += $result['imported'];
                $totalUpdated += $result['updated'];

                Log::info('Sync successful', [
                    'plaid_account_id' => $plaidAccount->id,
                    'imported' => $result['imported'],
                    'updated' => $result['updated']
                ]);
            } catch (\Exception $e) {
                $accountName = $plaidAccount->account ? $plaidAccount->account->name : 'Unknown';

                // Handle PRODUCT_NOT_READY error specifically
                if (str_contains($e->getMessage(), 'PRODUCT_NOT_READY')) {
                    Log::info('Account transaction data not ready yet', [
                        'plaid_account_id' => $plaidAccount->id,
                        'account_name' => $accountName,
                        'institution' => $plaidAccount->institution_name
                    ]);
                    // Don't add to errors array for this specific case
                    continue;
                }

                $errorMessage = "Error syncing account {$accountName}: {$e->getMessage()}";
                $errors[] = $errorMessage;

                Log::error('Sync error', [
                    'plaid_account_id' => $plaidAccount->id,
                    'account_id' => $plaidAccount->account_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        Log::info('Sync complete', [
            'total_imported' => $totalImported,
            'total_updated' => $totalUpdated,
            'error_count' => count($errors)
        ]);
        
        if (!empty($errors)) {
            // Log errors but don't show them to the user unless there were no successful syncs
            Log::error('Plaid sync errors', $errors);
            
            if ($totalImported === 0 && $totalUpdated === 0) {
                return redirect()->back()->with('error', 'Failed to sync transactions. Please try again.');
            }
        }
        
        return redirect()->back()->with('message', 
            "Synced {$totalImported} new and updated {$totalUpdated} transactions.");
    }
} 