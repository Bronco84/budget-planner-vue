<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\Transaction;
use App\Services\VirtualAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateToVirtualAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounts:migrate-to-virtual 
                            {--budget= : Specific budget ID to migrate}
                            {--dry-run : Show what would be migrated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing account/transaction relationships to use Airtable virtual accounts';

    public function __construct(
        protected VirtualAccountService $virtualAccountService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting migration to virtual accounts...');
        
        $dryRun = $this->option('dry-run');
        $budgetId = $this->option('budget');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get budgets to process
        $budgets = $budgetId 
            ? Budget::where('id', $budgetId)->get()
            : Budget::all();

        if ($budgets->isEmpty()) {
            $this->error('No budgets found to migrate');
            return 1;
        }

        foreach ($budgets as $budget) {
            $this->migrateBudget($budget, $dryRun);
        }

        $this->info('Migration completed!');
        return 0;
    }

    /**
     * Migrate a specific budget to virtual accounts
     */
    protected function migrateBudget(Budget $budget, bool $dryRun): void
    {
        $this->info("Processing budget: {$budget->name} (ID: {$budget->id})");

        // Get virtual accounts from Airtable
        $virtualAccounts = $this->virtualAccountService->getAccountsForBudget($budget);
        
        if ($virtualAccounts->isEmpty()) {
            $this->warn("  No virtual accounts found in Airtable for this budget");
            return;
        }

        $this->info("  Found {$virtualAccounts->count()} virtual accounts in Airtable");

        // Get legacy accounts that need migration
        $legacyAccounts = $budget->accounts()
            ->has('transactions') // Only migrate accounts with transactions
            ->with('transactions')
            ->get();

        if ($legacyAccounts->isEmpty()) {
            $this->info("  No legacy accounts with transactions found");
            return;
        }

        $this->info("  Found {$legacyAccounts->count()} legacy accounts with transactions");

        foreach ($legacyAccounts as $legacyAccount) {
            $this->migrateLegacyAccount($legacyAccount, $virtualAccounts, $dryRun);
        }
    }

    /**
     * Migrate a legacy account to virtual accounts
     */
    protected function migrateLegacyAccount($legacyAccount, $virtualAccounts, bool $dryRun): void
    {
        $this->line("    Migrating account: {$legacyAccount->name}");

        // Try to find matching virtual account
        $matchingVirtualAccount = $this->findMatchingVirtualAccount($legacyAccount, $virtualAccounts);

        if (!$matchingVirtualAccount) {
            $this->warn("      No matching virtual account found - transactions will remain with legacy account");
            return;
        }

        $this->info("      Matched with virtual account: {$matchingVirtualAccount['name']}");

        // Get transactions to migrate
        $transactions = $legacyAccount->transactions;
        $transactionCount = $transactions->count();

        if ($transactionCount === 0) {
            $this->info("      No transactions to migrate");
            return;
        }

        $this->info("      Migrating {$transactionCount} transactions...");

        if (!$dryRun) {
            DB::transaction(function () use ($transactions, $matchingVirtualAccount) {
                foreach ($transactions as $transaction) {
                    $transaction->update([
                        'account_id' => null, // Remove legacy account reference
                        'airtable_account_id' => $matchingVirtualAccount['airtable_id'],
                        'computed_account_name' => $matchingVirtualAccount['name'],
                    ]);
                }
            });
        }

        $this->info("      ✓ Migrated {$transactionCount} transactions");
    }

    /**
     * Try to find a matching virtual account for a legacy account
     */
    protected function findMatchingVirtualAccount($legacyAccount, $virtualAccounts): ?array
    {
        // Strategy 1: Exact name match
        $match = $virtualAccounts->firstWhere('name', $legacyAccount->name);
        if ($match) {
            return $match;
        }

        // Strategy 2: Partial name match (case insensitive)
        $legacyName = strtolower($legacyAccount->name);
        $match = $virtualAccounts->first(function ($virtualAccount) use ($legacyName) {
            $virtualName = strtolower($virtualAccount['name']);
            return str_contains($virtualName, $legacyName) || str_contains($legacyName, $virtualName);
        });
        if ($match) {
            return $match;
        }

        // Strategy 3: Account type match
        $match = $virtualAccounts->firstWhere('type', $legacyAccount->type);
        if ($match && $virtualAccounts->where('type', $legacyAccount->type)->count() === 1) {
            // Only match by type if there's exactly one account of that type
            return $match;
        }

        // Strategy 4: Interactive selection (if running interactively)
        if ($this->input->isInteractive()) {
            return $this->selectVirtualAccountInteractively($legacyAccount, $virtualAccounts);
        }

        return null;
    }

    /**
     * Allow user to select matching virtual account interactively
     */
    protected function selectVirtualAccountInteractively($legacyAccount, $virtualAccounts): ?array
    {
        $this->warn("      Could not automatically match account: {$legacyAccount->name}");
        $this->line("      Available virtual accounts:");

        $choices = ['Skip this account'];
        foreach ($virtualAccounts as $index => $virtualAccount) {
            $choices[] = "{$virtualAccount['name']} ({$virtualAccount['type']})";
        }

        $choice = $this->choice('Select matching virtual account', $choices);

        if ($choice === 'Skip this account') {
            return null;
        }

        // Find the selected virtual account
        $selectedIndex = array_search($choice, $choices) - 1; // -1 because of "Skip" option
        return $virtualAccounts->values()->get($selectedIndex);
    }

    /**
     * Show migration summary
     */
    protected function showSummary(Budget $budget): void
    {
        $virtualTransactions = Transaction::where('budget_id', $budget->id)
            ->whereNotNull('airtable_account_id')
            ->count();

        $legacyTransactions = Transaction::where('budget_id', $budget->id)
            ->whereNotNull('account_id')
            ->whereNull('airtable_account_id')
            ->count();

        $this->info("Migration Summary for {$budget->name}:");
        $this->info("  Virtual account transactions: {$virtualTransactions}");
        $this->info("  Legacy account transactions: {$legacyTransactions}");
    }
}
