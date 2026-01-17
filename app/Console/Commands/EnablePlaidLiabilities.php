<?php

namespace App\Console\Commands;

use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Services\PlaidService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnablePlaidLiabilities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:enable-liabilities 
                            {--dry-run : Run without making changes}
                            {--connection-id= : Only process specific connection ID}
                            {--auto-update : Automatically attempt to update liability data after identifying connections}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify Plaid connections with credit cards that lack liability data (manual diagnostic tool)';

    /**
     * Execute the console command.
     */
    public function handle(PlaidService $plaidService)
    {
        $dryRun = $this->option('dry-run');
        $connectionId = $this->option('connection-id');
        $autoUpdate = $this->option('auto-update');
        
        if ($dryRun) {
            $this->info('Running in dry-run mode. No changes will be made.');
        }

        $this->info('Scanning for Plaid connections with credit cards lacking liability data...');
        $this->newLine();

        // Find all credit card accounts without liability data
        $query = PlaidAccount::where('account_type', 'credit')
            ->where('account_subtype', 'credit card')
            ->whereNull('last_statement_balance_cents');

        if ($connectionId) {
            $query->where('plaid_connection_id', $connectionId);
        }

        $affectedAccounts = $query->with(['plaidConnection', 'account'])->get();

        if ($affectedAccounts->isEmpty()) {
            $this->info('✓ No credit card accounts found without liability data.');
            return Command::SUCCESS;
        }

        // Group by connection
        $connectionGroups = $affectedAccounts->groupBy('plaid_connection_id');

        $this->warn("Found {$affectedAccounts->count()} credit card account(s) across {$connectionGroups->count()} connection(s) without liability data:");
        $this->newLine();

        $successCount = 0;
        $failCount = 0;
        $skippedCount = 0;

        foreach ($connectionGroups as $connectionId => $accounts) {
            $connection = $accounts->first()->plaidConnection;
            
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line("Connection: {$connection->institution_name} (ID: {$connection->id})");
            $this->line("Item ID: {$connection->plaid_item_id}");
            $this->line("Budget: {$connection->budget->name}");
            $this->line("Affected Accounts:");
            
            foreach ($accounts as $account) {
                $this->line("  • {$account->account_name} (****{$account->account_mask})");
            }
            
            $this->newLine();

            if ($dryRun) {
                $this->info("  [DRY RUN] Would attempt to update liability data");
                $skippedCount += $accounts->count();
                continue;
            }

            if ($autoUpdate) {
                $this->info("  Attempting to fetch liability data...");
                
                try {
                    // Try to update liability data for the first account (which updates all cards on connection)
                    $firstAccount = $accounts->first();
                    $success = $plaidService->updateLiabilityData($firstAccount);
                    
                    if ($success) {
                        $this->info("  ✓ Successfully updated liability data for all cards on this connection");
                        $successCount += $accounts->count();
                    } else {
                        $this->warn("  ⚠ No liability data available from Plaid");
                        $this->line("  → This connection may need to be re-authenticated with liabilities product");
                        $this->line("  → Users can do this via the 'Update Statement Balance' button in the UI");
                        $failCount += $accounts->count();
                    }
                } catch (\Exception $e) {
                    $this->error("  ✗ Error: {$e->getMessage()}");
                    
                    if (str_contains($e->getMessage(), 'PRODUCT_NOT_READY') || 
                        str_contains($e->getMessage(), 'PRODUCTS_NOT_SUPPORTED')) {
                        $this->line("  → The liabilities product is not enabled for this Plaid Item");
                        $this->line("  → Users must re-authenticate via Plaid Link in update mode");
                    }
                    
                    $failCount += $accounts->count();
                }
            } else {
                $this->line("  → Run with --auto-update to attempt fetching liability data");
                $this->line("  → Or have users click 'Update Statement Balance' in the UI");
                $skippedCount += $accounts->count();
            }
            
            $this->newLine();
        }

        // Summary
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info('Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Successfully Updated', $successCount],
                ['Failed / Need Re-auth', $failCount],
                ['Skipped', $skippedCount],
                ['Total Accounts', $affectedAccounts->count()],
            ]
        );

        if ($failCount > 0) {
            $this->newLine();
            $this->warn('Action Required:');
            $this->line('Connections that failed need the liabilities scope added to their Plaid Item.');
            $this->line('Users must manually re-authenticate via the UI:');
            $this->line('  1. Go to account settings in the budget');
            $this->line('  2. Click "Update Statement Balance" on affected credit cards');
            $this->line('  3. Re-authenticate via Plaid Link in update mode');
            $this->line('  4. This adds the liabilities scope to the existing Item (preserves all data)');
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to attempt updates.');
        }

        return Command::SUCCESS;
    }
}

