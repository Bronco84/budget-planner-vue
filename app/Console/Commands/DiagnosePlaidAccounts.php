<?php

namespace App\Console\Commands;

use App\Models\PlaidAccount;
use App\Services\PlaidService;
use Illuminate\Console\Command;

class DiagnosePlaidAccounts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'plaid:diagnose-accounts {--institution= : Filter by institution name}';

    /**
     * The console command description.
     */
    protected $description = 'Diagnose what accounts are available through Plaid connections';

    /**
     * Execute the console command.
     */
    public function handle(PlaidService $plaidService): int
    {
        $this->info('🔍 Diagnosing Plaid account availability...');
        $this->newLine();

        // Get all Plaid connections
        $plaidAccounts = PlaidAccount::with('account')->get();

        if ($plaidAccounts->isEmpty()) {
            $this->error('❌ No Plaid connections found.');
            return 1;
        }

        $institutionFilter = $this->option('institution');

        foreach ($plaidAccounts as $plaidAccount) {
            if ($institutionFilter && stripos($plaidAccount->institution_name, $institutionFilter) === false) {
                continue;
            }

            $this->info("🏦 Institution: {$plaidAccount->institution_name}");
            $this->info("🔗 Connected Account: {$plaidAccount->account->name} (ID: {$plaidAccount->account->id})");
            $this->newLine();

            try {
                // Get all accounts from this Plaid connection
                $accounts = $plaidService->getAccounts($plaidAccount->access_token);
                
                $this->info("📊 Total accounts found: " . count($accounts));
                
                // Also check for liabilities (mortgages, credit cards, loans)
                try {
                    $liabilities = $plaidService->getLiabilities($plaidAccount->access_token);
                    $creditCards = $liabilities['credit'] ?? [];
                    $mortgages = $liabilities['mortgage'] ?? [];
                    $studentLoans = $liabilities['student'] ?? [];
                    $otherLiabilities = $liabilities['other'] ?? [];
                    
                    $totalLiabilities = count($creditCards) + count($mortgages) + count($studentLoans) + count($otherLiabilities);
                    $this->info("💳 Total liabilities found: " . $totalLiabilities);
                    
                    if ($totalLiabilities > 0) {
                        $this->info("  • Credit Cards: " . count($creditCards));
                        $this->info("  • Mortgages: " . count($mortgages));
                        $this->info("  • Student Loans: " . count($studentLoans));
                        $this->info("  • Other Liabilities: " . count($otherLiabilities));
                    }
                } catch (\Exception $e) {
                    $this->warn("⚠️  Could not fetch liabilities: " . $e->getMessage());
                }
                
                $this->newLine();

                foreach ($accounts as $index => $account) {
                    $isLinked = $account['account_id'] === $plaidAccount->plaid_account_id;
                    $status = $isLinked ? '✅ LINKED' : '⭕ AVAILABLE';
                    
                    $this->line("Account #" . ($index + 1) . " {$status}");
                    $this->line("  Name: {$account['name']}");
                    $this->line("  Type: {$account['type']}");
                    $this->line("  Subtype: " . ($account['subtype'] ?? 'none'));
                    $this->line("  Mask: " . ($account['mask'] ?? 'none'));
                    
                    if (isset($account['balances']['current'])) {
                        $balance = number_format($account['balances']['current'], 2);
                        $this->line("  Balance: \${$balance}");
                    }
                    
                    if (isset($account['balances']['available']) && $account['balances']['available'] !== $account['balances']['current']) {
                        $available = number_format($account['balances']['available'], 2);
                        $this->line("  Available: \${$available}");
                    }
                    
                    // Map the account type
                    $mappedType = $plaidService->mapPlaidAccountType($account);
                    $this->line("  Mapped Type: {$mappedType}");
                    
                    $this->newLine();
                }

                // Show unlinked accounts summary
                $unlinkedAccounts = array_filter($accounts, function($account) use ($plaidAccount) {
                    return $account['account_id'] !== $plaidAccount->plaid_account_id;
                });

                if (!empty($unlinkedAccounts)) {
                    $this->warn("🔄 " . count($unlinkedAccounts) . " accounts are available but not imported:");
                    foreach ($unlinkedAccounts as $account) {
                        $type = $account['subtype'] ?? $account['type'];
                        $this->line("  • {$account['name']} ({$type})");
                    }
                    $this->newLine();
                }

            } catch (\Exception $e) {
                $this->error("❌ Error fetching accounts: " . $e->getMessage());
            }

            $this->line("---");
            $this->newLine();
        }

        return 0;
    }
}
