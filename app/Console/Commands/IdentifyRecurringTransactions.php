<?php

namespace App\Console\Commands;

use App\Models\Account;
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
                            {--account_id= : The ID of the specific account to analyze (optional)}
                            {--months=3 : Number of months of historical data to analyze}
                            {--min-occurrences=2 : Minimum number of occurrences to consider a recurring pattern}
                            {--similarity-threshold=85 : Similarity threshold percentage for transaction descriptions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identifies potential recurring transactions from existing transactions with interactive account selection';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $budget = Budget::findOrFail($this->argument('budget_id'));
        $account = $this->selectAccount($budget);
        $minOccurrences = $this->option('min-occurrences');
        $days = 90;

        $this->info("Analyzing transactions for budget: {$budget->name}");
        $this->info("Selected account: {$account->name} ({$account->type})");

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
                $intervals[] = (int) $dates[$i]->diffInDays($dates[$i-1]);
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

    /**
     * Select an account from the budget either via command option or interactive selection.
     *
     * @param Budget $budget
     * @return Account
     */
    protected function selectAccount(Budget $budget): Account
    {
        // If account_id option is provided, use it
        if ($accountId = $this->option('account_id')) {
            $account = $budget->accounts()->where('id', $accountId)->first();
            if (!$account) {
                $this->error("Account with ID {$accountId} not found in budget {$budget->name}");
                exit(1);
            }
            return $account;
        }

        // Load all accounts for this budget
        $accounts = $budget->accounts()->orderBy('name')->get();
        
        if ($accounts->isEmpty()) {
            $this->error("No accounts found in budget {$budget->name}");
            exit(1);
        }

        // If only one account, use it automatically
        if ($accounts->count() === 1) {
            $this->info("Using the only available account: {$accounts->first()->name}");
            return $accounts->first();
        }

        // Interactive selection
        $this->info("Available accounts in budget '{$budget->name}':");
        $this->table(
            ['ID', 'Name', 'Type', 'Balance'],
            $accounts->map(function ($account) {
                return [
                    $account->id,
                    $account->name,
                    ucfirst($account->type),
                    '$' . number_format($account->current_balance_cents / 100, 2)
                ];
            })->toArray()
        );

        // Use Laravel's choice method with simple array of names
        $choices = $accounts->pluck('name', 'id')->toArray();
        $selectedAccountId = $this->choice(
            'Which account would you like to analyze?',
            $choices
        );

        return $accounts->firstWhere('name', $selectedAccountId);
    }
}
