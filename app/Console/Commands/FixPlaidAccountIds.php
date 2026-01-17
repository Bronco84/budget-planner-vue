<?php

namespace App\Console\Commands;

use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Services\PlaidService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixPlaidAccountIds extends Command
{
    protected $signature = 'plaid:fix-account-ids 
                            {--dry-run : Show what would be changed without making changes}
                            {--connection-id= : Only process specific connection ID}';
    
    protected $description = 'Fix mismatched Plaid account IDs by matching accounts based on mask and name';

    public function handle(PlaidService $plaidService)
    {
        $dryRun = $this->option('dry-run');
        $connectionId = $this->option('connection-id');

        if ($dryRun) {
            $this->warn('Running in dry-run mode. No changes will be made.');
            $this->newLine();
        }

        // Get connections to process
        $query = PlaidConnection::with('plaidAccounts');
        if ($connectionId) {
            $query->where('id', $connectionId);
        }
        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->error('No Plaid connections found.');
            return Command::FAILURE;
        }

        $this->info("Processing {$connections->count()} connection(s)...");
        $this->newLine();

        $totalFixed = 0;
        $totalMismatched = 0;

        foreach ($connections as $connection) {
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("Connection: {$connection->institution_name} (ID: {$connection->id})");
            
            $accessToken = $connection->access_token;
            if (!$accessToken) {
                $this->error("  No access token found, skipping.");
                continue;
            }

            // Get current accounts from Plaid
            try {
                $plaidAccounts = $plaidService->getAccounts($accessToken);
            } catch (\Exception $e) {
                $this->error("  Failed to fetch accounts from Plaid: {$e->getMessage()}");
                continue;
            }

            if (empty($plaidAccounts)) {
                $this->warn("  No accounts returned from Plaid.");
                continue;
            }

            $this->line("  Plaid returned " . count($plaidAccounts) . " account(s)");
            $this->newLine();

            // Build a lookup of Plaid accounts by mask
            $plaidByMask = [];
            foreach ($plaidAccounts as $pa) {
                $mask = $pa['mask'] ?? null;
                if ($mask) {
                    $plaidByMask[$mask] = $pa;
                }
            }

            // Check each local PlaidAccount
            foreach ($connection->plaidAccounts as $localAccount) {
                $this->line("  Local: {$localAccount->account_name} (****{$localAccount->account_mask})");
                $this->line("    DB plaid_account_id: {$localAccount->plaid_account_id}");
                
                // Find matching Plaid account by mask
                $matchingPlaidAccount = $plaidByMask[$localAccount->account_mask] ?? null;
                
                if (!$matchingPlaidAccount) {
                    $this->warn("    ⚠ No matching account found in Plaid by mask");
                    continue;
                }

                $plaidAccountId = $matchingPlaidAccount['account_id'];
                $this->line("    Plaid account_id: {$plaidAccountId}");

                if ($localAccount->plaid_account_id === $plaidAccountId) {
                    $this->info("    ✓ IDs match - no fix needed");
                } else {
                    $totalMismatched++;
                    $this->error("    ✗ IDs MISMATCH!");
                    $this->line("      Old: {$localAccount->plaid_account_id}");
                    $this->line("      New: {$plaidAccountId}");

                    if (!$dryRun) {
                        $localAccount->update(['plaid_account_id' => $plaidAccountId]);
                        $this->info("    → Fixed! Updated plaid_account_id");
                        $totalFixed++;
                    } else {
                        $this->warn("    → Would fix (dry-run)");
                    }
                }

                $this->newLine();
            }
        }

        // Summary
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info('Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Mismatched IDs Found', $totalMismatched],
                ['Fixed', $dryRun ? "0 (dry-run)" : $totalFixed],
            ]
        );

        if ($dryRun && $totalMismatched > 0) {
            $this->newLine();
            $this->warn("Run without --dry-run to apply fixes.");
        }

        if ($totalFixed > 0) {
            $this->newLine();
            $this->info("After fixing, run: php artisan plaid:test-liability-update");
        }

        return Command::SUCCESS;
    }
}
