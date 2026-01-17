<?php

namespace App\Console\Commands;

use App\Models\PlaidAccount;
use App\Services\PlaidService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestLiabilityUpdate extends Command
{
    protected $signature = 'plaid:test-liability-update {plaid_account_id?}';
    protected $description = 'Test liability data update for credit cards with detailed logging';

    public function handle(PlaidService $plaidService)
    {
        $plaidAccountId = $this->argument('plaid_account_id');

        if ($plaidAccountId) {
            $plaidAccounts = PlaidAccount::where('id', $plaidAccountId)->get();
        } else {
            // Get all credit card accounts
            $plaidAccounts = PlaidAccount::where('account_type', 'credit')
                ->where('account_subtype', 'credit card')
                ->with(['plaidConnection', 'account'])
                ->get();
        }

        if ($plaidAccounts->isEmpty()) {
            $this->error('No credit card accounts found');
            return Command::FAILURE;
        }

        $this->info("Found {$plaidAccounts->count()} credit card account(s)");
        $this->newLine();

        foreach ($plaidAccounts as $plaidAccount) {
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("Testing: {$plaidAccount->account_name} (ID: {$plaidAccount->id})");
            $this->line("Plaid Account ID: {$plaidAccount->plaid_account_id}");
            $this->line("Type: {$plaidAccount->account_type} / {$plaidAccount->account_subtype}");
            $this->line("Institution: {$plaidAccount->institution_name}");
            $this->newLine();

            // Check if it's a credit card
            $isCreditCard = $plaidAccount->isCreditCard();
            $this->line("Is Credit Card: " . ($isCreditCard ? 'YES' : 'NO'));

            if (!$isCreditCard) {
                $this->warn("Skipping - not a credit card");
                continue;
            }

            // Check current liability data
            $this->line("Current Data:");
            $this->line("  Statement Balance: " . ($plaidAccount->last_statement_balance_cents ?? 'NULL'));
            $this->line("  Credit Limit: " . ($plaidAccount->credit_limit_cents ?? 'NULL'));
            $this->line("  APR: " . ($plaidAccount->apr_percentage ?? 'NULL'));
            $this->newLine();

            // Attempt update
            $this->info("Attempting liability data update...");
            
            try {
                $result = $plaidService->updateLiabilityData($plaidAccount);
                
                if ($result) {
                    $this->info("✓ Update successful");
                    
                    // Refresh and show new data
                    $plaidAccount->refresh();
                    $this->line("Updated Data:");
                    $this->line("  Statement Balance: " . ($plaidAccount->last_statement_balance_cents ?? 'NULL'));
                    $this->line("  Credit Limit: " . ($plaidAccount->credit_limit_cents ?? 'NULL'));
                    $this->line("  APR: " . ($plaidAccount->apr_percentage ?? 'NULL'));
                    $this->line("  Statement Date: " . ($plaidAccount->last_statement_issue_date ?? 'NULL'));
                    $this->line("  Due Date: " . ($plaidAccount->next_payment_due_date ?? 'NULL'));
                } else {
                    $this->warn("✗ Update returned false - check logs for details");
                }
            } catch (\Exception $e) {
                $this->error("✗ Update failed: " . $e->getMessage());
                $this->line("Trace: " . $e->getTraceAsString());
            }

            $this->newLine();
        }

        $this->info("Check storage/logs/laravel.log for detailed debug information");
        
        return Command::SUCCESS;
    }
}
