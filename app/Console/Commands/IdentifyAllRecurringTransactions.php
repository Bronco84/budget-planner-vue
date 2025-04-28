<?php

namespace App\Console\Commands;

use App\Models\Budget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class IdentifyAllRecurringTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:identify-all-recurring
                           {--months=3 : Number of months of historical data to analyze}
                           {--min-occurrences=2 : Minimum number of occurrences to consider a recurring pattern}
                           {--similarity-threshold=85 : Similarity threshold percentage for transaction descriptions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identifies potential recurring transactions for all budgets';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $months = $this->option('months');
        $minOccurrences = $this->option('min-occurrences');
        $similarityThreshold = $this->option('similarity-threshold');

        // Get all active budgets
        $budgets = Budget::all();
        
        if ($budgets->isEmpty()) {
            $this->info("No budgets found to analyze.");
            return 0;
        }

        $this->info("Found " . $budgets->count() . " budgets to analyze.");
        $totalTemplates = 0;

        foreach ($budgets as $budget) {
            $this->info("Analyzing budget: {$budget->name} (ID: {$budget->id})");
            
            try {
                // Run the identify-recurring command for this budget
                $exitCode = Artisan::call('transactions:identify-recurring', [
                    'budget_id' => $budget->id,
                    '--months' => $months,
                    '--min-occurrences' => $minOccurrences,
                    '--similarity-threshold' => $similarityThreshold,
                ]);
                
                // Get command output and display
                $output = Artisan::output();
                $this->line($output);
                
                // Count templates created
                if (preg_match('/Created (\d+) recurring transaction templates/', $output, $matches)) {
                    $totalTemplates += (int) $matches[1];
                }
                
                if ($exitCode !== 0) {
                    $this->warn("Command returned non-zero exit code {$exitCode} for budget {$budget->id}");
                }
            } catch (\Exception $e) {
                $this->error("Error processing budget {$budget->id}: " . $e->getMessage());
                Log::error("Error in identify-all-recurring command", [
                    'budget_id' => $budget->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->info("Command completed. Created {$totalTemplates} recurring transaction templates across all budgets.");
        return 0;
    }
} 