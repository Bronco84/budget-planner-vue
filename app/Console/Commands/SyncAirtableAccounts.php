<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Services\HybridAccountService;
use Illuminate\Console\Command;

class SyncAirtableAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounts:sync-airtable 
                            {--budget= : Specific budget ID to sync}
                            {--all : Sync all budgets}
                            {--force : Force sync even if recently synced}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Airtable virtual accounts to local Account records';

    public function __construct(
        protected HybridAccountService $hybridAccountService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $budgetId = $this->option('budget');
        $syncAll = $this->option('all');
        $force = $this->option('force');

        if (!$budgetId && !$syncAll) {
            $this->error('Please specify either --budget=ID or --all');
            return 1;
        }

        // Get budgets to sync
        $budgets = $budgetId 
            ? Budget::where('id', $budgetId)->get()
            : Budget::all();

        if ($budgets->isEmpty()) {
            $this->error('No budgets found to sync');
            return 1;
        }

        $this->info('Starting Airtable account synchronization...');

        $totalSynced = 0;
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalErrors = 0;

        foreach ($budgets as $budget) {
            $this->line("Processing budget: {$budget->name} (ID: {$budget->id})");

            try {
                $result = $this->hybridAccountService->syncAccountsForBudget($budget);
                
                $this->info("  ✓ Synced: {$result['synced']}, Created: {$result['created']}, Updated: {$result['updated']}");
                
                if (!empty($result['errors'])) {
                    $this->warn("  ⚠ Errors: " . count($result['errors']));
                    foreach ($result['errors'] as $error) {
                        $this->line("    - {$error['airtable_id']}: {$error['error']}");
                    }
                }

                $totalSynced += $result['synced'];
                $totalCreated += $result['created'];
                $totalUpdated += $result['updated'];
                $totalErrors += count($result['errors']);

            } catch (\Exception $e) {
                $this->error("  ✗ Failed to sync budget {$budget->name}: {$e->getMessage()}");
                $totalErrors++;
            }
        }

        $this->newLine();
        $this->info('Synchronization completed!');
        $this->table(['Metric', 'Count'], [
            ['Total Accounts Synced', $totalSynced],
            ['Accounts Created', $totalCreated],
            ['Accounts Updated', $totalUpdated],
            ['Errors', $totalErrors],
        ]);

        return $totalErrors > 0 ? 1 : 0;
    }
}
