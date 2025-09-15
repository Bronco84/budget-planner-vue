<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Services\HybridAccountService;
use Illuminate\Console\Command;

class SyncHybridAccounts extends Command
{
    protected $signature = 'accounts:sync-hybrid {budget_id?} {--all : Sync all budgets}';
    protected $description = 'Sync Airtable virtual accounts to local Account models for recurring transactions';

    public function handle(HybridAccountService $hybridAccountService)
    {
        $this->info('🔄 Syncing Airtable accounts to local database...');
        $this->newLine();

        if ($this->option('all')) {
            $budgets = Budget::all();
            $this->info("Syncing accounts for all {$budgets->count()} budgets...");
        } elseif ($budgetId = $this->argument('budget_id')) {
            $budgets = Budget::where('id', $budgetId)->get();
            if ($budgets->isEmpty()) {
                $this->error("Budget with ID {$budgetId} not found.");
                return 1;
            }
        } else {
            // Interactive selection
            $budgets = Budget::all();
            if ($budgets->isEmpty()) {
                $this->error('No budgets found.');
                return 1;
            }

            $choices = $budgets->mapWithKeys(function ($budget) {
                return [$budget->id => "{$budget->name} (ID: {$budget->id})"];
            })->toArray();

            $selectedId = $this->choice('Select a budget to sync accounts for:', $choices);
            $budgets = Budget::where('id', $selectedId)->get();
        }

        $totalSynced = 0;
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalErrors = 0;

        foreach ($budgets as $budget) {
            $this->info("Syncing accounts for budget: {$budget->name}");
            
            try {
                $result = $hybridAccountService->syncAccountsForBudget($budget);
                
                $this->line("  ✅ Synced: {$result['synced']} accounts");
                $this->line("  ➕ Created: {$result['created']} new accounts");
                $this->line("  🔄 Updated: {$result['updated']} existing accounts");
                
                if (!empty($result['errors'])) {
                    $this->line("  ❌ Errors: " . count($result['errors']));
                    foreach ($result['errors'] as $error) {
                        $this->warn("    • Account {$error['airtable_id']}: {$error['error']}");
                    }
                }

                $totalSynced += $result['synced'];
                $totalCreated += $result['created'];
                $totalUpdated += $result['updated'];
                $totalErrors += count($result['errors']);

                // Mark any accounts that are no longer in Airtable as inactive
                $inactive = $hybridAccountService->markMissingAccountsInactive($budget);
                if ($inactive > 0) {
                    $this->line("  🚫 Marked inactive: {$inactive} accounts no longer in Airtable");
                }

            } catch (\Exception $e) {
                $this->error("  ❌ Failed to sync budget {$budget->name}: {$e->getMessage()}");
                $totalErrors++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('📊 Sync Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Synced', $totalSynced],
                ['Created', $totalCreated],
                ['Updated', $totalUpdated],
                ['Errors', $totalErrors],
            ]
        );

        if ($totalErrors === 0) {
            $this->info('🎉 All accounts synced successfully!');
            $this->info('💡 You can now run recurring transaction commands that require local Account models.');
            return 0;
        } else {
            $this->warn("⚠️  Sync completed with {$totalErrors} errors. Check logs for details.");
            return 1;
        }
    }
}
