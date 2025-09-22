<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BankFeed;
use App\Models\BankFeedTransaction;
use App\Models\Transaction;
use App\Services\BankFeed\BankFeedImportInterface;
use App\Services\BankFeed\PlaidImportStrategy;
use App\Services\BankFeed\AirtableImportStrategy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankFeedService
{
    protected array $strategies = [];

    public function __construct(
        PlaidImportStrategy $plaidStrategy,
        AirtableImportStrategy $airtableStrategy
    ) {
        $this->strategies = [
            BankFeed::SOURCE_PLAID => $plaidStrategy,
            BankFeed::SOURCE_AIRTABLE => $airtableStrategy,
        ];
    }

    /**
     * Get the import strategy for a source type.
     */
    public function getStrategy(string $sourceType): BankFeedImportInterface
    {
        if (!isset($this->strategies[$sourceType])) {
            throw new \InvalidArgumentException("Unsupported source type: {$sourceType}");
        }

        return $this->strategies[$sourceType];
    }

    /**
     * Get all available source types.
     */
    public function getAvailableSourceTypes(): array
    {
        $sourceTypes = [];
        foreach ($this->strategies as $type => $strategy) {
            $sourceTypes[$type] = $strategy->getDisplayName();
        }
        return $sourceTypes;
    }

    /**
     * Connect a new bank feed source to an account.
     */
    public function connectSource(Account $account, string $sourceType, array $credentials): array
    {
        try {
            DB::beginTransaction();

            // Validate source type
            $strategy = $this->getStrategy($sourceType);

            // Validate credentials
            $validation = $strategy->validateCredentials($credentials);
            if (!$validation['success']) {
                return $validation;
            }

            // Check if a bank feed already exists for this source
            $existingFeed = $account->getBankFeedBySource($sourceType);
            if ($existingFeed && $existingFeed->isActive()) {
                return [
                    'success' => false,
                    'error' => "Account already has an active {$strategy->getDisplayName()} connection",
                ];
            }

            // Attempt connection
            $connectionResult = $strategy->connect($credentials);
            if (!$connectionResult['success']) {
                return $connectionResult;
            }

            // Create or update bank feed
            $bankFeed = BankFeed::updateOrCreate(
                [
                    'account_id' => $account->id,
                    'source_type' => $sourceType,
                ],
                [
                    'budget_id' => $account->budget_id,
                    'connection_config' => $connectionResult['config'],
                    'source_account_id' => $credentials['source_account_id'] ?? null,
                    'institution_name' => $credentials['institution_name'] ?? $strategy->getDisplayName(),
                    'status' => BankFeed::STATUS_ACTIVE,
                    'error_message' => null,
                ]
            );

            // Test the connection
            $testResult = $strategy->testConnection($bankFeed);
            if (!$testResult['success']) {
                $bankFeed->update([
                    'status' => BankFeed::STATUS_ERROR,
                    'error_message' => $testResult['error'],
                ]);
                
                DB::rollBack();
                return $testResult;
            }

            // Try to update balance
            try {
                $balanceData = $strategy->updateBalance($bankFeed);
                $bankFeed->update($balanceData);
            } catch (\Exception $e) {
                // Balance update failure shouldn't prevent connection
                Log::warning('Failed to update balance during connection', [
                    'bank_feed_id' => $bankFeed->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'bank_feed' => $bankFeed,
                'message' => "Successfully connected to {$strategy->getDisplayName()}",
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to connect bank feed source', [
                'account_id' => $account->id,
                'source_type' => $sourceType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to connect: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Sync transactions for a bank feed.
     */
    public function syncTransactions(BankFeed $bankFeed, int $days = 120): array
    {
        try {
            if (!$bankFeed->isActive()) {
                return [
                    'success' => false,
                    'error' => 'Bank feed is not active',
                ];
            }

            $strategy = $this->getStrategy($bankFeed->source_type);
            
            $endDate = Carbon::today();
            $startDate = Carbon::today()->subDays($days);

            // Get transactions from source
            $sourceTransactions = $strategy->getTransactions($bankFeed, $startDate, $endDate);

            $imported = 0;
            $updated = 0;
            $processed = 0;

            DB::beginTransaction();

            foreach ($sourceTransactions as $transactionData) {
                // Store or update bank feed transaction
                $bankFeedTransaction = BankFeedTransaction::updateOrCreate(
                    [
                        'bank_feed_id' => $bankFeed->id,
                        'source_transaction_id' => $transactionData['source_transaction_id'],
                    ],
                    $transactionData
                );

                if ($bankFeedTransaction->wasRecentlyCreated) {
                    $imported++;
                } else {
                    $updated++;
                }

                // Create Transaction if it doesn't exist and transaction is cleared
                if (!$bankFeedTransaction->isProcessed() && $bankFeedTransaction->isCleared()) {
                    try {
                        $transaction = $bankFeedTransaction->createTransaction();
                        $processed++;
                        
                        Log::info('Created transaction from bank feed', [
                            'transaction_id' => $transaction->id,
                            'bank_feed_transaction_id' => $bankFeedTransaction->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to create transaction from bank feed transaction', [
                            'bank_feed_transaction_id' => $bankFeedTransaction->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Update sync timestamp
            $bankFeed->update(['last_sync_at' => now()]);

            // Update balance
            try {
                $balanceData = $strategy->updateBalance($bankFeed);
                $bankFeed->update($balanceData);
            } catch (\Exception $e) {
                Log::warning('Failed to update balance during sync', [
                    'bank_feed_id' => $bankFeed->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'processed' => $processed,
                'total_transactions' => count($sourceTransactions),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Update bank feed status to error
            $bankFeed->update([
                'status' => BankFeed::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Failed to sync bank feed transactions', [
                'bank_feed_id' => $bankFeed->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Sync failed: ' . $e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'processed' => 0,
            ];
        }
    }

    /**
     * Sync transactions for all active bank feeds in a budget.
     */
    public function syncAllBankFeeds(int $budgetId, int $days = 120): array
    {
        $bankFeeds = BankFeed::where('budget_id', $budgetId)
            ->where('status', BankFeed::STATUS_ACTIVE)
            ->get();

        $results = [];
        $totalImported = 0;
        $totalUpdated = 0;
        $totalProcessed = 0;

        foreach ($bankFeeds as $bankFeed) {
            $result = $this->syncTransactions($bankFeed, $days);
            
            $results[] = [
                'bank_feed_id' => $bankFeed->id,
                'source_type' => $bankFeed->source_type,
                'account_name' => $bankFeed->account->name,
                'result' => $result,
            ];

            if ($result['success']) {
                $totalImported += $result['imported'];
                $totalUpdated += $result['updated'];
                $totalProcessed += $result['processed'];
            }
        }

        return [
            'success' => true,
            'bank_feeds_synced' => count($bankFeeds),
            'total_imported' => $totalImported,
            'total_updated' => $totalUpdated,
            'total_processed' => $totalProcessed,
            'results' => $results,
        ];
    }

    /**
     * Disconnect a bank feed source.
     */
    public function disconnectSource(BankFeed $bankFeed): bool
    {
        try {
            $strategy = $this->getStrategy($bankFeed->source_type);
            
            // Attempt to disconnect from source
            $disconnected = $strategy->disconnect($bankFeed);
            
            // Update bank feed status regardless of source disconnection result
            $bankFeed->update([
                'status' => BankFeed::STATUS_DISCONNECTED,
                'error_message' => null,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to disconnect bank feed', [
                'bank_feed_id' => $bankFeed->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Test connection for a bank feed.
     */
    public function testConnection(BankFeed $bankFeed): array
    {
        try {
            $strategy = $this->getStrategy($bankFeed->source_type);
            return $strategy->testConnection($bankFeed);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get configuration fields for a source type.
     */
    public function getConfigFields(string $sourceType): array
    {
        try {
            $strategy = $this->getStrategy($sourceType);
            return $strategy->getConfigFields();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Process unprocessed bank feed transactions into regular transactions.
     */
    public function processUnprocessedTransactions(BankFeed $bankFeed): array
    {
        $unprocessedTransactions = $bankFeed->bankFeedTransactions()
            ->unprocessed()
            ->cleared()
            ->get();

        $processed = 0;
        $errors = [];

        foreach ($unprocessedTransactions as $bankFeedTransaction) {
            try {
                $bankFeedTransaction->createTransaction();
                $processed++;
            } catch (\Exception $e) {
                $errors[] = [
                    'bank_feed_transaction_id' => $bankFeedTransaction->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'total_unprocessed' => $unprocessedTransactions->count(),
        ];
    }
}
