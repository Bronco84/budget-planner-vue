<?php

namespace App\Console\Commands;

use App\Services\AirtableService;
use Illuminate\Console\Command;

class AnalyzeAirtableData extends Command
{
    protected $signature = 'airtable:analyze 
                           {--accounts : Analyze only accounts table}
                           {--transactions : Analyze only transactions table}
                           {--sample=5 : Number of sample records to fetch}';

    protected $description = 'Analyze Airtable data structure and compare with Plaid implementation';

    public function handle(AirtableService $airtableService): int
    {
        if (!$airtableService->isConfigured()) {
            $this->error('Airtable service is not properly configured. Please check your environment variables:');
            $this->line('- AIRTABLE_API_KEY');
            $this->line('- AIRTABLE_BASE_ID');
            $this->line('- AIRTABLE_ACCOUNTS_TABLE (optional, defaults to "accounts")');
            $this->line('- AIRTABLE_TRANSACTIONS_TABLE (optional, defaults to "transactions")');
            return 1;
        }

        $this->info('🔍 Analyzing Airtable data structure...');
        $this->newLine();

        $sampleCount = (int) $this->option('sample');
        
        // Determine what to analyze
        $analyzeAccounts = $this->option('accounts') || (!$this->option('accounts') && !$this->option('transactions'));
        $analyzeTransactions = $this->option('transactions') || (!$this->option('accounts') && !$this->option('transactions'));

        if ($analyzeAccounts) {
            $this->analyzeAccounts($airtableService, $sampleCount);
        }

        if ($analyzeTransactions) {
            $this->analyzeTransactions($airtableService, $sampleCount);
        }

        $this->newLine();
        $this->info('📊 Generating comprehensive analysis...');
        
        try {
            $analysis = $airtableService->analyzeDataStructure();
            $this->displayAnalysis($analysis);
        } catch (\Exception $e) {
            $this->error('Failed to generate analysis: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function analyzeAccounts(AirtableService $airtableService, int $sampleCount): void
    {
        $this->info('💰 Analyzing Accounts Table');
        $this->line('─────────────────────────');

        try {
            $schema = $airtableService->getAccountsSchema();
            
            if (!$schema) {
                $this->warn('Could not retrieve accounts schema');
                return;
            }

            $this->line("📋 Fields found: " . count($schema['fields']));
            foreach ($schema['fields'] as $field) {
                $this->line("  • {$field}");
            }

            $this->newLine();
            $accounts = $airtableService->getAccounts(null, null, $sampleCount);
            $this->line("📊 Sample records: {$accounts->count()}");
            
            if ($accounts->isNotEmpty()) {
                $this->line("🔍 Sample data structure:");
                $firstAccount = $accounts->first();
                $this->displayRecord($firstAccount, 'Account');
            }

        } catch (\Exception $e) {
            $this->error("Failed to analyze accounts: {$e->getMessage()}");
        }

        $this->newLine();
    }

    protected function analyzeTransactions(AirtableService $airtableService, int $sampleCount): void
    {
        $this->info('💳 Analyzing Transactions Table');
        $this->line('──────────────────────────────');

        try {
            $schema = $airtableService->getTransactionsSchema();
            
            if (!$schema) {
                $this->warn('Could not retrieve transactions schema');
                return;
            }

            $this->line("📋 Fields found: " . count($schema['fields']));
            foreach ($schema['fields'] as $field) {
                $this->line("  • {$field}");
            }

            $this->newLine();
            $transactions = $airtableService->getTransactions(null, null, $sampleCount);
            $this->line("📊 Sample records: {$transactions->count()}");
            
            if ($transactions->isNotEmpty()) {
                $this->line("🔍 Sample data structure:");
                $firstTransaction = $transactions->first();
                $this->displayRecord($firstTransaction, 'Transaction');
            }

        } catch (\Exception $e) {
            $this->error("Failed to analyze transactions: {$e->getMessage()}");
        }

        $this->newLine();
    }

    protected function displayRecord(array $record, string $type): void
    {
        $this->line("  {$type} ID: {$record['id']}");
        $this->line("  Created: {$record['createdTime']}");
        $this->line("  Fields:");
        
        foreach ($record['fields'] as $field => $value) {
            $displayValue = is_array($value) ? json_encode($value) : $value;
            $displayValue = strlen($displayValue) > 100 ? substr($displayValue, 0, 100) . '...' : $displayValue;
            $this->line("    {$field}: {$displayValue}");
        }
    }

    protected function displayAnalysis(array $analysis): void
    {
        $this->newLine();
        $this->info('🔗 Field Mapping Suggestions (Airtable → Plaid)');
        $this->line('─────────────────────────────────────────────────');

        if (isset($analysis['field_mappings']['accounts']) && !empty($analysis['field_mappings']['accounts'])) {
            $this->line('📋 Accounts:');
            foreach ($analysis['field_mappings']['accounts'] as $airtableField => $suggestions) {
                $this->line("  {$airtableField} →");
                foreach ($suggestions as $suggestion) {
                    $confidence = $suggestion['confidence'];
                    $field = $suggestion['field'];
                    $emoji = match($confidence) {
                        'exact' => '🎯',
                        'high' => '✅',
                        'medium' => '🔶',
                        default => '❓'
                    };
                    $similarity = isset($suggestion['similarity']) ? " ({$suggestion['similarity']})" : '';
                    $this->line("    {$emoji} {$field} ({$confidence}{$similarity})");
                }
            }
            $this->newLine();
        }

        if (isset($analysis['field_mappings']['transactions']) && !empty($analysis['field_mappings']['transactions'])) {
            $this->line('💳 Transactions:');
            foreach ($analysis['field_mappings']['transactions'] as $airtableField => $suggestions) {
                $this->line("  {$airtableField} →");
                foreach ($suggestions as $suggestion) {
                    $confidence = $suggestion['confidence'];
                    $field = $suggestion['field'];
                    $emoji = match($confidence) {
                        'exact' => '🎯',
                        'high' => '✅',
                        'medium' => '🔶',
                        default => '❓'
                    };
                    $similarity = isset($suggestion['similarity']) ? " ({$suggestion['similarity']})" : '';
                    $this->line("    {$emoji} {$field} ({$confidence}{$similarity})");
                }
            }
        }

        $this->newLine();
        $this->info('💡 Migration Recommendations:');
        $this->line('────────────────────────────');
        $this->generateMigrationRecommendations($analysis);
    }

    protected function generateMigrationRecommendations(array $analysis): void
    {
        $recommendations = [];

        // Analyze accounts table
        if (isset($analysis['accounts']['schema']['fields'])) {
            $accountsFields = $analysis['accounts']['schema']['fields'];
            
            $recommendations[] = "1. 🏦 Create new AirtableAccount model to mirror your PlaidAccount structure";
            $recommendations[] = "2. 📊 Add migration for airtable_accounts table with these suggested columns:";
            
            foreach ($accountsFields as $field) {
                $recommendations[] = "   • {$field} (map to appropriate data type)";
            }
        }

        // Analyze transactions table  
        if (isset($analysis['transactions']['schema']['fields'])) {
            $transactionsFields = $analysis['transactions']['schema']['fields'];
            
            $recommendations[] = "3. 💳 Create new AirtableTransaction model to mirror your PlaidTransaction structure";
            $recommendations[] = "4. 📊 Add migration for airtable_transactions table with these suggested columns:";
            
            foreach ($transactionsFields as $field) {
                $recommendations[] = "   • {$field} (map to appropriate data type)";
            }
        }

        $recommendations[] = "5. 🔄 Create AirtableAccountService similar to PlaidService for data synchronization";
        $recommendations[] = "6. 🎯 Update existing Transaction model to support both Plaid and Airtable sources";
        $recommendations[] = "7. 🔧 Consider creating a unified interface/contract for financial data providers";
        $recommendations[] = "8. 📱 Update controllers to handle both Plaid and Airtable linking flows";

        foreach ($recommendations as $recommendation) {
            $this->line($recommendation);
        }

        $this->newLine();
        $this->comment('💡 Tip: Run this command with --sample=20 to get more sample data for analysis');
        $this->comment('💡 Tip: Check your Airtable base structure to ensure field names match expectations');
    }
}
