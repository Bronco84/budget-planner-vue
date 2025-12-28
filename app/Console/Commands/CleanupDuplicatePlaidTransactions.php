<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicatePlaidTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:cleanup-duplicates {--dry-run : Show what would be deleted without actually deleting} {--days=3 : Date tolerance in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify and merge duplicate pending Plaid transactions caused by date changes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $dateTolerance = (int) $this->option('days');
        
        $this->info('Searching for duplicate pending Plaid transactions...');
        $this->info("Date tolerance: ±{$dateTolerance} days");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get all Plaid-imported transactions grouped by account
        $accounts = Transaction::where('is_plaid_imported', true)
            ->whereNotNull('plaid_transaction_id')
            ->distinct('account_id')
            ->pluck('account_id');

        $totalDuplicatesFound = 0;
        $totalMerged = 0;

        foreach ($accounts as $accountId) {
            $this->line("\nProcessing account ID: {$accountId}");
            
            // Get all transactions for this account
            $transactions = Transaction::where('account_id', $accountId)
                ->where('is_plaid_imported', true)
                ->whereNotNull('plaid_transaction_id')
                ->orderBy('date')
                ->orderBy('created_at')
                ->get();

            $processedIds = [];

            foreach ($transactions as $transaction) {
                // Skip if already processed
                if (in_array($transaction->id, $processedIds)) {
                    continue;
                }

                // Find potential duplicates
                $duplicates = Transaction::where('account_id', $accountId)
                    ->where('is_plaid_imported', true)
                    ->whereNotNull('plaid_transaction_id')
                    ->where('id', '!=', $transaction->id)
                    ->whereNotIn('id', $processedIds)
                    // Match amount within $1 tolerance
                    ->whereBetween('amount_in_cents', [
                        $transaction->amount_in_cents - 100,
                        $transaction->amount_in_cents + 100
                    ])
                    // Match date within tolerance
                    ->whereBetween('date', [
                        Carbon::parse($transaction->date)->subDays($dateTolerance),
                        Carbon::parse($transaction->date)->addDays($dateTolerance)
                    ])
                    // Match description similarity
                    ->where(function ($query) use ($transaction) {
                        // Extract numeric identifiers
                        preg_match_all('/\d{4,}/', $transaction->description, $matches);
                        if (!empty($matches[0])) {
                            foreach ($matches[0] as $identifier) {
                                $query->orWhere('description', 'LIKE', "%{$identifier}%");
                            }
                        } else {
                            // Fallback: match first 20 characters
                            $prefix = substr($transaction->description, 0, 20);
                            $query->where('description', 'LIKE', "{$prefix}%");
                        }
                    })
                    ->get();

                if ($duplicates->isNotEmpty()) {
                    $totalDuplicatesFound += $duplicates->count();
                    
                    $this->warn("  Found duplicate group:");
                    $originalAmount = number_format($transaction->amount_in_cents / 100, 2);
                    $this->line("    Original: ID {$transaction->id} | {$transaction->date} | \${$originalAmount} | {$transaction->description}");
                    
                    foreach ($duplicates as $duplicate) {
                        $duplicateAmount = number_format($duplicate->amount_in_cents / 100, 2);
                        $this->line("    Duplicate: ID {$duplicate->id} | {$duplicate->date} | \${$duplicateAmount} | {$duplicate->description}");
                        
                        if (!$dryRun) {
                            // Keep the most recent transaction (by created_at)
                            if ($duplicate->created_at > $transaction->created_at) {
                                // The duplicate is newer, so delete the original and keep the duplicate
                                $this->line("      → Deleting ID {$transaction->id} (keeping newer ID {$duplicate->id})");
                                $transaction->delete();
                                $processedIds[] = $transaction->id;
                                $transaction = $duplicate; // Continue with the newer one
                            } else {
                                // The original is newer, delete the duplicate
                                $this->line("      → Deleting ID {$duplicate->id} (keeping newer ID {$transaction->id})");
                                $duplicate->delete();
                                $processedIds[] = $duplicate->id;
                            }
                            $totalMerged++;
                        }
                    }
                }

                $processedIds[] = $transaction->id;
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Total duplicates found: {$totalDuplicatesFound}");
        
        if ($dryRun) {
            $this->warn("  No changes made (dry run mode)");
            $this->info("\nRun without --dry-run to actually merge duplicates:");
            $this->comment("  php artisan plaid:cleanup-duplicates");
        } else {
            $this->info("  Total duplicates merged: {$totalMerged}");
        }

        return 0;
    }
}
