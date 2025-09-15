<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Services\AirtableService;
use App\Services\VirtualAccountService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAirtableTransactions extends Command
{
    protected $signature = 'airtable:sync-transactions {budget_id?}';
    protected $description = 'Sync transactions from Airtable to local database';

    public function __construct(
        protected AirtableService $airtableService,
        protected VirtualAccountService $virtualAccountService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔄 Starting Airtable transaction sync...');
        $this->newLine();

        if (!$this->airtableService->isConfigured()) {
            $this->error('❌ Airtable service is not configured');
            return 1;
        }

        // Get budget
        $budgetId = $this->argument('budget_id');
        if ($budgetId) {
            $budget = Budget::find($budgetId);
            if (!$budget) {
                $this->error("❌ Budget with ID {$budgetId} not found");
                return 1;
            }
            $budgets = collect([$budget]);
        } else {
            $budgets = Budget::all();
        }

        $totalImported = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($budgets as $budget) {
            $this->info("📋 Processing budget: {$budget->name} (ID: {$budget->id})");
            
            $result = $this->syncBudgetTransactions($budget);
            
            $totalImported += $result['imported'];
            $totalUpdated += $result['updated'];
            $totalSkipped += $result['skipped'];
            $totalErrors += $result['errors'];
            
            $this->line("  ✅ Imported: {$result['imported']}");
            $this->line("  🔄 Updated: {$result['updated']}");
            $this->line("  ⏭️  Skipped: {$result['skipped']}");
            $this->line("  ❌ Errors: {$result['errors']}");
            $this->newLine();
        }

        $this->info('📊 Total Summary:');
        $this->line("  ✅ Total Imported: {$totalImported}");
        $this->line("  🔄 Total Updated: {$totalUpdated}");
        $this->line("  ⏭️  Total Skipped: {$totalSkipped}");
        $this->line("  ❌ Total Errors: {$totalErrors}");

        return 0;
    }

    protected function syncBudgetTransactions(Budget $budget): array
    {
        try {
            // Get all Airtable transactions
            $allTransactions = $this->airtableService->getAllRecords('Transactions');
            
            // Get virtual accounts for mapping
            $virtualAccounts = $this->virtualAccountService->getAccountsForBudget($budget);
            
            // Create mapping of Airtable IDs to account info
            $accountMapping = [];
            foreach ($virtualAccounts as $account) {
                $accountMapping[$account['airtable_id']] = $account;
            }

            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            DB::transaction(function () use ($allTransactions, $budget, $accountMapping, &$imported, &$updated, &$skipped, &$errors) {
                foreach ($allTransactions as $transactionData) {
                    try {
                        $result = $this->processTransaction($budget, $transactionData, $accountMapping);
                        
                        switch ($result['action']) {
                            case 'imported':
                                $imported++;
                                break;
                            case 'updated':
                                $updated++;
                                break;
                            case 'skipped':
                                $skipped++;
                                break;
                        }
                    } catch (\Exception $e) {
                        $errors++;
                        $this->error("❌ Error processing transaction {$transactionData['id']}: {$e->getMessage()}");
                    }
                }
            });

            // Update budget sync status
            $budget->update([
                'airtable_base_id' => config('services.airtable.base_id'),
                'last_airtable_sync' => now(),
                'airtable_sync_summary' => [
                    'last_sync' => now()->toISOString(),
                    'total_imported' => $imported,
                    'total_updated' => $updated,
                    'total_skipped' => $skipped,
                    'total_errors' => $errors,
                ]
            ]);

            return [
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            $this->error("❌ Failed to sync budget {$budget->id}: {$e->getMessage()}");
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1];
        }
    }

    protected function processTransaction(Budget $budget, array $transactionData, array $accountMapping): array
    {
        $fields = $transactionData['fields'];
        $accountField = $fields['**Account'] ?? null;
        
        // Skip if no account linkage
        if (!is_array($accountField) || empty($accountField)) {
            return ['action' => 'skipped', 'reason' => 'No account linkage'];
        }
        
        // Get the first linked account
        $airtableAccountId = $accountField[0];
        
        // Skip if we don't have this account in our mapping
        if (!isset($accountMapping[$airtableAccountId])) {
            return ['action' => 'skipped', 'reason' => 'Account not in mapping'];
        }
        
        $account = $accountMapping[$airtableAccountId];
        
        // Find the corresponding local account
        $localAccount = Account::where('budget_id', $budget->id)
            ->where('airtable_account_id', $airtableAccountId)
            ->first();
        
        // Prepare transaction data
        $transactionFields = [
            'budget_id' => $budget->id,
            'account_id' => $localAccount ? $localAccount->id : null, // Link to local account
            'description' => $fields['*Name'] ?? 'Airtable Transaction',
            'category' => $fields['*Notes'] ?? 'Uncategorized',
            'amount_in_cents' => round(($fields['**USD'] ?? 0) * 100),
            'date' => Carbon::parse($fields['**Date'] ?? now()),
            'airtable_transaction_id' => $transactionData['id'],
            'airtable_account_id' => $airtableAccountId,
            'is_airtable_imported' => true,
            'computed_account_name' => $account['name'],
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
}