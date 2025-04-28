<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\RecurringTransactionTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class IdentifyRecurringTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:identify-recurring
                            {budget_id : The ID of the budget to analyze}
                            {--months=3 : Number of months of historical data to analyze}
                            {--min-occurrences=2 : Minimum number of occurrences to consider a recurring pattern}
                            {--similarity-threshold=85 : Similarity threshold percentage for transaction descriptions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identifies potential recurring transactions from existing transactions';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $budgetId = $this->argument('budget_id');
        $months = $this->option('months');
        $minOccurrences = $this->option('min-occurrences');
        $similarityThreshold = $this->option('similarity-threshold');

        $budget = Budget::findOrFail($budgetId);
        $this->info("Analyzing transactions for budget: {$budget->name}");

        // Get transactions from the past X months
        $startDate = Carbon::now()->subMonths($months);
        $transactions = Transaction::where('budget_id', $budgetId)
            ->where('date', '>=', $startDate)
            ->orderBy('date')
            ->get();

        if ($transactions->isEmpty()) {
            $this->error("No transactions found in the specified time period.");
            return 1;
        }

        $this->info("Found {$transactions->count()} transactions to analyze.");

        // Group similar transactions
        $groups = $this->groupSimilarTransactions($transactions, $similarityThreshold);
        $this->info("Identified " . count($groups) . " groups of similar transactions.");

        // Filter groups that have at least the minimum number of occurrences
        $recurringGroups = array_filter($groups, function ($group) use ($minOccurrences) {
            return count($group) >= $minOccurrences;
        });

        $this->info("Found " . count($recurringGroups) . " potential recurring transaction patterns.");

        // Process each group to create recurring transaction templates
        $createdCount = 0;
        foreach ($recurringGroups as $group) {
            // Detect frequency
            $frequency = $this->detectFrequency($group);
            
            // Skip if we couldn't determine a frequency
            if (!$frequency) {
                continue;
            }
            
            // Create recurring transaction template
            $template = $this->createRecurringTemplate($group, $frequency, $budget);
            
            if ($template) {
                $createdCount++;
                $this->info("Created recurring template: '{$template->description}' ({$frequency})");
            }
        }

        $this->info("Created {$createdCount} recurring transaction templates.");
        return 0;
    }

    /**
     * Group similar transactions based on description and category.
     *
     * @param Collection $transactions
     * @param int $similarityThreshold
     * @return array
     */
    protected function groupSimilarTransactions(Collection $transactions, int $similarityThreshold): array
    {
        $groups = [];

        foreach ($transactions as $transaction) {
            $placed = false;

            foreach ($groups as $key => $group) {
                // Check first transaction in the group
                $firstTransaction = $group[0];
                
                if ($this->areTransactionsSimilar($transaction, $firstTransaction, $similarityThreshold)) {
                    $groups[$key][] = $transaction;
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $groups[] = [$transaction];
            }
        }

        return $groups;
    }

    /**
     * Check if two transactions are similar enough to be considered the same pattern.
     *
     * @param Transaction $transaction1
     * @param Transaction $transaction2
     * @param int $threshold
     * @return bool
     */
    protected function areTransactionsSimilar(Transaction $transaction1, Transaction $transaction2, int $threshold): bool
    {
        // Same category is required
        if ($transaction1->category !== $transaction2->category) {
            return false;
        }

        // Check if amounts are within 10% of each other
        $amountDiff = abs($transaction1->amount_in_cents - $transaction2->amount_in_cents);
        $avgAmount = ($transaction1->amount_in_cents + $transaction2->amount_in_cents) / 2;
        $amountPercentDiff = ($avgAmount > 0) ? ($amountDiff / $avgAmount) * 100 : 100;
        
        if ($amountPercentDiff > 10) {
            return false;
        }

        // Check description similarity
        $similarity = $this->calculateSimilarityPercentage(
            $transaction1->description,
            $transaction2->description
        );

        return $similarity >= $threshold;
    }

    /**
     * Calculate similarity percentage between two strings using Levenshtein distance.
     *
     * @param string $str1
     * @param string $str2
     * @return float
     */
    protected function calculateSimilarityPercentage(string $str1, string $str2): float
    {
        $levenshtein = levenshtein($str1, $str2);
        $maxLength = max(strlen($str1), strlen($str2));
        
        if ($maxLength === 0) {
            return 100;
        }
        
        return (1 - $levenshtein / $maxLength) * 100;
    }

    /**
     * Detect the frequency of transactions in a group.
     *
     * @param array $transactions
     * @return string|null
     */
    protected function detectFrequency(array $transactions): ?string
    {
        if (count($transactions) < 2) {
            return null;
        }

        // Sort by date
        usort($transactions, function ($a, $b) {
            return strtotime($a->date) - strtotime($b->date);
        });

        // Calculate intervals in days between consecutive transactions
        $intervals = [];
        for ($i = 1; $i < count($transactions); $i++) {
            $previous = Carbon::parse($transactions[$i-1]->date);
            $current = Carbon::parse($transactions[$i]->date);
            $intervals[] = $current->diffInDays($previous);
        }

        // Calculate the average interval
        $avgInterval = array_sum($intervals) / count($intervals);

        // Determine frequency based on average interval
        if ($avgInterval <= 1) {
            return 'daily';
        } elseif ($avgInterval >= 6 && $avgInterval <= 8) {
            return 'weekly';
        } elseif ($avgInterval >= 13 && $avgInterval <= 16) {
            return 'biweekly';
        } elseif ($avgInterval >= 28 && $avgInterval <= 31) {
            return 'monthly';
        } elseif ($avgInterval >= 89 && $avgInterval <= 93) {
            return 'quarterly';
        } elseif ($avgInterval >= 180 && $avgInterval <= 186) {
            return 'biannually';
        } elseif ($avgInterval >= 364 && $avgInterval <= 366) {
            return 'annually';
        }

        return null;
    }

    /**
     * Create a recurring transaction template from a group of similar transactions.
     *
     * @param array $transactions
     * @param string $frequency
     * @param Budget $budget
     * @return RecurringTransactionTemplate|null
     */
    protected function createRecurringTemplate(array $transactions, string $frequency, Budget $budget): ?RecurringTransactionTemplate
    {
        if (empty($transactions)) {
            return null;
        }

        // Use the most recent transaction as the template
        $mostRecent = end($transactions);
        
        // Check for amount variance to determine if it's a dynamic amount
        $amounts = array_map(function ($t) {
            return $t->amount_in_cents;
        }, $transactions);
        
        $minAmount = min($amounts);
        $maxAmount = max($amounts);
        $isDynamicAmount = ($maxAmount - $minAmount) > ($minAmount * 0.05); // 5% variance
        
        // Calculate the average amount
        $avgAmount = array_sum($amounts) / count($amounts);

        // Create the template
        try {
            $template = new RecurringTransactionTemplate();
            $template->budget_id = $budget->id;
            $template->account_id = $mostRecent->account_id;
            $template->description = $mostRecent->description;
            $template->category = $mostRecent->category;
            $template->amount_in_cents = round($avgAmount);
            $template->frequency = $frequency;
            $template->is_dynamic_amount = $isDynamicAmount;
            $template->start_date = now();
            $template->auto_generate = true;
            $template->save();
            
            return $template;
        } catch (\Exception $e) {
            $this->error("Failed to create template: " . $e->getMessage());
            return null;
        }
    }
} 