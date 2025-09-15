<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Services\AirtableService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AirtableSyncService
{
    public function __construct(
        protected AirtableService $airtableService
    ) {}

    /**
     * Sync accounts from Airtable and return account mapping
     * This doesn't create local account records, just maps Airtable accounts to existing app accounts
     */
    public function getAccountMapping(Budget $budget): Collection
    {
        if (!$this->airtableService->isConfigured()) {
            throw new \Exception('Airtable service is not configured');
        }

        $airtableAccounts = $this->airtableService->getAllRecords('accounts');
        
        return $airtableAccounts->map(function ($record) use ($budget) {
            $fields = $record['fields'];
            
            return [
                'airtable_id' => $record['id'],
                'name' => $fields['account_name'] ?? $fields['name'] ?? 'Unknown Account',
                'institution' => $fields['institution_name'] ?? $fields['institution'] ?? 'Unknown',
                'type' => $fields['account_type'] ?? $fields['type'] ?? 'Unknown',
                'balance' => $fields['current_balance'] ?? $fields['balance'] ?? 0,
                'external_id' => $fields['external_account_id'] ?? $fields['account_id'] ?? null,
                'metadata' => $record
            ];
        });
    }

    /**
     * Sync transactions from Airtable for a specific virtual account
     */
    public function syncTransactionsForVirtualAccount(Budget $budget, string $airtableAccountId): array
    {
        if (!$this->airtableService->isConfigured()) {
            throw new \Exception('Airtable service is not configured');
        }

        // Get transactions from Airtable for this account
        $filterFormula = "{{account_id}} = '{$airtableAccountId}'";
        $airtableTransactions = $this->airtableService->getAllRecords('transactions', $filterFormula);

        $imported = 0;
        $updated = 0;
        $errors = [];

        DB::transaction(function () use ($airtableTransactions, $budget, $airtableAccountId, &$imported, &$updated, &$errors) {
            foreach ($airtableTransactions as $transactionData) {
                try {
                    $result = $this->processVirtualTransaction($budget, $airtableAccountId, $transactionData);
                    if ($result['action'] === 'imported') {
                        $imported++;
                    } elseif ($result['action'] === 'updated') {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'airtable_id' => $transactionData['id'],
                        'error' => $e->getMessage()
                    ];
                    Log::warning('Failed to process Airtable transaction', [
                        'airtable_id' => $transactionData['id'],
                        'error' => $e->getMessage(),
                        'airtable_account_id' => $airtableAccountId
                    ]);
                }
            }
        });

        Log::info('Airtable transaction sync completed', [
            'budget_id' => $budget->id,
            'airtable_account_id' => $airtableAccountId,
            'imported' => $imported,
            'updated' => $updated,
            'errors' => count($errors)
        ]);

        // Update budget sync summary
        $this->updateBudgetSyncSummary($budget, $imported, $updated, count($errors));

        return [
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors
        ];
    }

    /**
     * Process a single transaction from Airtable for a virtual account
     */
    protected function processVirtualTransaction(Budget $budget, string $airtableAccountId, array $transactionData): array
    {
        $fields = $transactionData['fields'];
        
        // Map Airtable fields to our Transaction model fields
        $transactionFields = [
            'budget_id' => $budget->id,
            'account_id' => null, // No longer using legacy account_id
            'description' => $this->getTransactionDescription($fields),
            'category' => $this->getTransactionCategory($fields),
            'amount_in_cents' => $this->getAmountInCents($fields),
            'date' => $this->getTransactionDate($fields),
            'airtable_transaction_id' => $transactionData['id'],
            'airtable_account_id' => $airtableAccountId,
            'is_airtable_imported' => true,
            'computed_account_name' => $this->getAccountNameFromAirtable($airtableAccountId),
            'airtable_metadata' => $transactionData,
        ];

        // Check if transaction already exists
        $existingTransaction = Transaction::where('airtable_transaction_id', $transactionData['id'])->first();

        if ($existingTransaction) {
            // Update existing transaction
            $existingTransaction->update($transactionFields);
            return ['action' => 'updated', 'transaction' => $existingTransaction];
        } else {
            // Create new transaction
            $transaction = Transaction::create($transactionFields);
            return ['action' => 'imported', 'transaction' => $transaction];
        }
    }

    /**
     * Process a single transaction from Airtable (legacy method for backward compatibility)
     */
    protected function processTransaction(Account $account, array $transactionData): array
    {
        $fields = $transactionData['fields'];
        
        // Map Airtable fields to our Transaction model fields
        $transactionFields = [
            'budget_id' => $account->budget_id,
            'account_id' => $account->id,
            'description' => $this->getTransactionDescription($fields),
            'category' => $this->getTransactionCategory($fields),
            'amount_in_cents' => $this->getAmountInCents($fields),
            'date' => $this->getTransactionDate($fields),
            'airtable_transaction_id' => $transactionData['id'],
            'airtable_account_id' => $fields['account_id'] ?? null,
            'is_airtable_imported' => true,
            'airtable_metadata' => $transactionData,
        ];

        // Check if transaction already exists
        $existingTransaction = Transaction::where('airtable_transaction_id', $transactionData['id'])->first();

        if ($existingTransaction) {
            // Update existing transaction
            $existingTransaction->update($transactionFields);
            return ['action' => 'updated', 'transaction' => $existingTransaction];
        } else {
            // Create new transaction
            $transaction = Transaction::create($transactionFields);
            return ['action' => 'imported', 'transaction' => $transaction];
        }
    }

    /**
     * Extract transaction description from Airtable fields
     */
    protected function getTransactionDescription(array $fields): string
    {
        return $fields['*Name'] ?? 
               $fields['description'] ?? 
               $fields['name'] ?? 
               $fields['merchant_name'] ?? 
               $fields['merchant'] ??
               'Airtable Transaction';
    }

    /**
     * Extract transaction category from Airtable fields
     */
    protected function getTransactionCategory(array $fields): string
    {
        return $fields['*Notes'] ?? 
               $fields['category'] ?? 
               $fields['primary_category'] ?? 
               $fields['transaction_category'] ??
               'Uncategorized';
    }

    /**
     * Extract and convert amount to cents
     */
    protected function getAmountInCents(array $fields): int
    {
        $amount = $fields['**USD'] ?? 
                  $fields['amount'] ?? 
                  $fields['transaction_amount'] ?? 
                  $fields['value'] ?? 0;

        // Convert to cents and handle negative values properly
        return round($amount * 100);
    }

    /**
     * Extract transaction date
     */
    protected function getTransactionDate(array $fields): Carbon
    {
        $dateString = $fields['**Date'] ?? 
                      $fields['date'] ?? 
                      $fields['transaction_date'] ?? 
                      $fields['created_date'] ?? 
                      now()->toDateString();

        return Carbon::parse($dateString);
    }

    /**
     * Sync all transactions for a budget from Airtable
     */
    public function syncAllTransactions(Budget $budget): array
    {
        $totalImported = 0;
        $totalUpdated = 0;
        $allErrors = [];

        // Get account mapping
        $accountMapping = $this->getAccountMapping($budget);

        foreach ($budget->accounts as $account) {
            // You could implement logic here to map app accounts to Airtable accounts
            // For now, this would need manual mapping or some identifier matching
            Log::info('Account sync would need mapping logic', [
                'account_id' => $account->id,
                'account_name' => $account->name
            ]);
        }

        return [
            'total_imported' => $totalImported,
            'total_updated' => $totalUpdated,
            'total_errors' => count($allErrors),
            'errors' => $allErrors
        ];
    }

    /**
     * Get summary of Airtable data for analysis
     */
    public function getDataSummary(): array
    {
        if (!$this->airtableService->isConfigured()) {
            return ['error' => 'Airtable service is not configured'];
        }

        try {
            $accounts = $this->airtableService->getAllRecords('accounts');
            $transactions = $this->airtableService->getAllRecords('transactions');

            return [
                'accounts_count' => $accounts->count(),
                'transactions_count' => $transactions->count(),
                'sample_account' => $accounts->first(),
                'sample_transaction' => $transactions->first(),
                'last_updated' => now()
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Update budget sync summary with Airtable sync results
     */
    protected function updateBudgetSyncSummary(Budget $budget, int $imported, int $updated, int $errors): void
    {
        $currentSummary = $budget->airtable_sync_summary ?? [];
        
        $newSummary = [
            'last_sync' => now()->toISOString(),
            'total_imported' => ($currentSummary['total_imported'] ?? 0) + $imported,
            'total_updated' => ($currentSummary['total_updated'] ?? 0) + $updated,
            'total_errors' => ($currentSummary['total_errors'] ?? 0) + $errors,
            'airtable_base_id' => config('services.airtable.base_id'),
            'recent_syncs' => array_slice([
                [
                    'timestamp' => now()->toISOString(),
                    'imported' => $imported,
                    'updated' => $updated,
                    'errors' => $errors
                ],
                ...($currentSummary['recent_syncs'] ?? [])
            ], 0, 10) // Keep last 10 sync records
        ];

        $budget->update([
            'airtable_base_id' => config('services.airtable.base_id'),
            'last_airtable_sync' => now(),
            'airtable_sync_summary' => $newSummary
        ]);
    }

    /**
     * Get account name from Airtable for caching
     */
    protected function getAccountNameFromAirtable(string $airtableAccountId): ?string
    {
        try {
            $accountRecord = $this->airtableService->getRecord('accounts', $airtableAccountId);
            $fields = $accountRecord['fields'] ?? [];
            return $fields['account_name'] ?? $fields['name'] ?? null;
        } catch (\Exception $e) {
            Log::warning('Could not fetch account name from Airtable', [
                'airtable_account_id' => $airtableAccountId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
