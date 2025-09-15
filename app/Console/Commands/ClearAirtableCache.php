<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Services\VirtualTransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearAirtableCache extends Command
{
    protected $signature = 'airtable:clear-cache {budget_id?}';
    protected $description = 'Clear Airtable cache to force fresh data retrieval';

    public function __construct(
        protected VirtualTransactionService $virtualTransactionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $budgetId = $this->argument('budget_id');

        if ($budgetId) {
            $budget = Budget::find($budgetId);
            if (!$budget) {
                $this->error("Budget with ID {$budgetId} not found");
                return 1;
            }
            $budgets = collect([$budget]);
        } else {
            $budgets = Budget::all();
        }

        $this->info('🧹 Clearing Airtable cache...');
        $this->newLine();

        foreach ($budgets as $budget) {
            // Clear transaction cache
            $this->virtualTransactionService->clearCache($budget);
            
            // Clear account cache
            Cache::forget("budget_{$budget->id}_airtable_accounts");
            
            $this->line("✅ Cleared cache for budget: {$budget->name} (ID: {$budget->id})");
        }

        $this->newLine();
        $this->info('🔄 Cache cleared successfully!');
        $this->info('💡 Next request will fetch fresh data from Airtable and cache for 12 hours');

        return 0;
    }
}