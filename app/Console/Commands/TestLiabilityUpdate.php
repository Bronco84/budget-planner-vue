<?php

namespace App\Console\Commands;

use App\Models\PlaidAccount;
use App\Services\PlaidService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TestLiabilityUpdate extends Command
{
    protected $signature = 'plaid:test-liability-update {plaid_account_id?} {--detailed : Show detailed explanation of Plaid data}';

    protected $description = 'Test liability data update for credit cards with detailed logging and diagnostics';

    public function handle(PlaidService $plaidService)
    {
        $plaidAccountId = $this->argument('plaid_account_id');
        $verbose = $this->option('detailed');

        // Show educational info about Plaid statement balances
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info('║              PLAID STATEMENT BALANCE DIAGNOSTICS                 ║');
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        if ($verbose) {
            $this->warn('Understanding Plaid Statement Balances:');
            $this->line('  • "Statement Balance" = Balance from your LAST billing statement');
            $this->line('  • "Current Balance" = What you owe RIGHT NOW (including new charges)');
            $this->line('  • Statement balances update once per month when your statement closes');
            $this->line('  • Plaid receives statement data 1-3 days after your bank generates it');
            $this->line('  • If statement seems old, your new statement may not have closed yet');
            $this->newLine();
        }

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
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $accountName = $plaidAccount->account?->name ?? $plaidAccount->account_name;
            $this->info("Account: {$accountName}");
            $this->line('Institution: '.($plaidAccount->plaidConnection?->institution_name ?? 'Unknown'));
            $this->line("Mask: ****{$plaidAccount->account_mask}");
            $this->newLine();

            // Check if it's a credit card
            $isCreditCard = $plaidAccount->isCreditCard();
            if (! $isCreditCard) {
                $this->warn('⊘ Skipping - not a credit card');

                continue;
            }

            // Show BEFORE state
            $this->line('┌─ CURRENT STORED DATA ─────────────────────────────────────────────');
            $this->displayAccountData($plaidAccount, 'before');
            $this->newLine();

            // Attempt update
            $this->info('⟳ Fetching latest data from Plaid...');

            try {
                $result = $plaidService->updateLiabilityData($plaidAccount);

                if ($result) {
                    $this->info('✓ Data retrieved successfully from Plaid');

                    // Refresh and show AFTER state
                    $plaidAccount->refresh();
                    $this->newLine();
                    $this->line('┌─ UPDATED DATA FROM PLAID ─────────────────────────────────────────');
                    $this->displayAccountData($plaidAccount, 'after');

                    // Show analysis
                    $this->analyzeStatementData($plaidAccount);
                } else {
                    $this->warn('✗ Update returned false');
                    $this->displayTroubleshootingHelp($plaidAccount);
                }
            } catch (\Exception $e) {
                $this->error('✗ Update failed: '.$e->getMessage());
                if ($verbose) {
                    $this->line('Trace: '.$e->getTraceAsString());
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('Tip: Check storage/logs/laravel.log for raw Plaid API response data');
        $this->info('Tip: Run with --detailed for more detailed explanations');

        return Command::SUCCESS;
    }

    /**
     * Display account data in a formatted table.
     */
    protected function displayAccountData(PlaidAccount $plaidAccount, string $phase): void
    {
        $currentBalance = $plaidAccount->current_balance_cents;
        $statementBalance = $plaidAccount->last_statement_balance_cents;
        $statementDate = $plaidAccount->last_statement_issue_date;
        $dueDate = $plaidAccount->next_payment_due_date;
        $creditLimit = $plaidAccount->credit_limit_cents;
        $liabilityUpdated = $plaidAccount->liability_updated_at;

        // Format values
        $currentBalanceFmt = $currentBalance !== null
            ? '$'.number_format($currentBalance / 100, 2)
            : 'Not available';
        $statementBalanceFmt = $statementBalance !== null
            ? '$'.number_format($statementBalance / 100, 2)
            : 'Not available';
        $creditLimitFmt = $creditLimit !== null
            ? '$'.number_format($creditLimit / 100, 2)
            : 'Not available';

        // Calculate statement age
        $statementAge = 'N/A';
        if ($statementDate) {
            $days = (int) Carbon::parse($statementDate)->diffInDays(now());
            $statementAge = "{$days} days ago";
        }

        $this->line("│  Current Balance:    {$currentBalanceFmt}");
        $this->line("│  Statement Balance:  {$statementBalanceFmt}");
        $this->line('│  Statement Date:     '.($statementDate ? $statementDate->format('M j, Y')." ({$statementAge})" : 'Not available'));
        $this->line('│  Payment Due:        '.($dueDate ? $dueDate->format('M j, Y') : 'Not available'));
        $this->line("│  Credit Limit:       {$creditLimitFmt}");
        $this->line('│  APR:                '.($plaidAccount->apr_percentage ? $plaidAccount->apr_percentage.'%' : 'Not available'));
        $this->line('│  Last Synced:        '.($liabilityUpdated ? $liabilityUpdated->format('M j, Y g:i A') : 'Never'));
        $this->line('└────────────────────────────────────────────────────────────────────');
    }

    /**
     * Analyze the statement data and provide insights.
     */
    protected function analyzeStatementData(PlaidAccount $plaidAccount): void
    {
        $this->newLine();
        $this->line('┌─ ANALYSIS ────────────────────────────────────────────────────────');

        $currentBalance = $plaidAccount->current_balance_cents ?? 0;
        $statementBalance = $plaidAccount->last_statement_balance_cents ?? 0;
        $statementDate = $plaidAccount->last_statement_issue_date;

        // Calculate spending since statement
        $spendingSinceStatement = $currentBalance - $statementBalance;
        $spendingFmt = '$'.number_format(abs($spendingSinceStatement) / 100, 2);

        if ($spendingSinceStatement > 0) {
            $this->line("│  Spending since last statement: +{$spendingFmt}");
        } elseif ($spendingSinceStatement < 0) {
            $this->line("│  Payments/credits since statement: -{$spendingFmt}");
        } else {
            $this->line('│  No change since last statement');
        }

        // Check statement age and provide guidance
        if ($statementDate) {
            $daysSinceStatement = (int) Carbon::parse($statementDate)->diffInDays(now());

            if ($daysSinceStatement > 35) {
                $this->warn("│  Warning: Statement is {$daysSinceStatement} days old (unusual)");
                $this->line('│     -> Your bank may not have sent new statement data to Plaid');
                $this->line('│     -> Try re-linking the account if this persists');
            } elseif ($daysSinceStatement > 28) {
                $this->line("│  Statement is {$daysSinceStatement} days old");
                $this->line('│     -> A new statement should be generated soon');
                $this->line("│     -> Plaid will update within 1-3 days after it's available");
            } else {
                $this->info("│  OK: Statement age ({$daysSinceStatement} days) is within normal range");
            }

            // Estimate next statement
            $nextStatementEstimate = Carbon::parse($statementDate)->addMonth();
            $daysUntilNext = now()->diffInDays($nextStatementEstimate, false);
            if ($daysUntilNext > 0) {
                $this->line('│  Next statement expected around: '.$nextStatementEstimate->format('M j, Y'));
            }
        } else {
            $this->warn('│  Warning: No statement data available');
            $this->line('│     → This account may require re-linking with liabilities consent');
        }

        $this->line('└────────────────────────────────────────────────────────────────────');
    }

    /**
     * Display troubleshooting help when update fails.
     */
    protected function displayTroubleshootingHelp(PlaidAccount $plaidAccount): void
    {
        $this->newLine();
        $this->warn('┌─ TROUBLESHOOTING ─────────────────────────────────────────────────');
        $this->line('│');
        $this->line('│  Common reasons for missing statement data:');
        $this->line('│');
        $this->line('│  1. ADDITIONAL_CONSENT_REQUIRED');
        $this->line('│     → Account was linked before liabilities product was enabled');
        $this->line("│     → Solution: Use 'Update Connection' to re-authenticate");
        $this->line('│');
        $this->line('│  2. PRODUCTS_NOT_SUPPORTED');
        $this->line("│     → This institution doesn't provide statement data to Plaid");
        $this->line('│     → Solution: Statement data unavailable for this card');
        $this->line('│');
        $this->line('│  3. Account not matched in Plaid response');
        $this->line("│     → Check storage/logs/laravel.log for 'No matching PlaidAccount'");
        $this->line('│     → The Plaid account ID may have changed');
        $this->line('│');
        $this->warn('└────────────────────────────────────────────────────────────────────');
    }
}
