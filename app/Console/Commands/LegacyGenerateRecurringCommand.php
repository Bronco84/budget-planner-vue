<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\RecurringTransactionTemplate;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class LegacyGenerateRecurringCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'budget:create-recurring-templates {budget_id} {account_id} {--min-occurrences=2} {--days=90}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create recurring transaction templates from existing transactions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $budget = Budget::findOrFail($this->argument('budget_id'));
        $account = Account::findOrFail($this->argument('account_id'));
        $minOccurrences = $this->option('min-occurrences');
        $days = $this->option('days');

        $this->info("Analyzing transactions for budget: {$budget->description}");

        // Get transactions from the last X days
        $transactions = Transaction::where('budget_id', $budget->id)
            ->where('account_id', $account->id)
            ->where('date', '>=', now()->subDays($days))
            ->whereNull('recurring_transaction_template_id')
            ->orderBy('date')
            ->get();

        // Group transactions by description
        $groups = $transactions->groupBy('description');
        $templates = [];

        foreach ($groups as $description => $group) {
            if ($group->count() < $minOccurrences) {
                continue;
            }

            // Calculate average amount and date interval
            $amounts = $group->pluck('amount_in_cents')->sort()->values();
            $medianAmount = $amounts[floor($amounts->count() / 2)];

            $dates = $group->pluck('date')->map(function ($date) {
                return Carbon::parse($date);
            })->sort();

            // Determine if it's monthly or weekly based on average interval
            $intervals = [];
            for ($i = 1; $i < $dates->count(); $i++) {
                $intervals[] = $dates[$i]->diffInDays($dates[$i-1]);
            }
            $avgInterval = array_sum($intervals) / count($intervals);

            $frequency = 'monthly';
            $dayOfMonth = $dates->first()->day;
            $dayOfWeek = null;

            if ($avgInterval <= 14) {
                $frequency = $avgInterval <= 8 ? 'weekly' : 'biweekly';
                $dayOfMonth = null;
                $dayOfWeek = $dates->first()->dayOfWeek;
            }

            // Format date pattern for display
            $datePattern = match($frequency) {
                'monthly' => "Day {$dayOfMonth} of each month",
                'weekly' => "Every " . Carbon::now()->startOfWeek()->addDays($dayOfWeek)->format('l'),
                'biweekly' => "Every other " . Carbon::now()->startOfWeek()->addDays($dayOfWeek)->format('l'),
                default => "Unknown pattern"
            };

            $templateInfo = [
                'description' => $description,
                'amount' => '$' . number_format(abs($medianAmount / 100), 2),
                'frequency' => $frequency,
                'occurrences' => $group->count(),
                'date_pattern' => $datePattern,
                'amount_range' => '$' . number_format(abs($amounts->min() / 100), 2) . ' - $' . number_format(abs($amounts->max() / 100), 2),
            ];

            if ($this->confirm(
                "Create recurring template?\n" .
                "Description: {$templateInfo['description']}\n" .
                "Amount: {$templateInfo['amount']} (Range: {$templateInfo['amount_range']})\n" .
                "Frequency: {$templateInfo['frequency']}\n" .
                "Pattern: {$templateInfo['date_pattern']}\n" .
                "Occurrences: {$templateInfo['occurrences']}"
            )) {
                $sample = $group->first();
                $template = new RecurringTransactionTemplate([
                    'budget_id' => $budget->id,
                    'description' => $description,
                    'amount_in_cents' => $medianAmount,
                    'category' => $sample->category,
                    'account_id' => $sample->account_id,
                    'frequency' => $frequency,
                    'day_of_month' => $dayOfMonth,
                    'day_of_week' => $dayOfWeek,
                    'week_of_month' => null,
                    'start_date' => $dates->first(),
                    'created_by' => 1,
                ]);

                $template->save();
                $templates[] = $template;

                // Update existing transactions to link them to the template
                $group->each(function ($transaction) use ($template) {
                    $transaction->recurring_transaction_template_id = $template->id;
                    $transaction->save();
                });
            }
        }

        $this->info("Created " . count($templates) . " recurring transaction templates.");
    }
}
