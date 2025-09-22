<?php

namespace App\Services\BankFeed;

use App\Models\BankFeed;
use App\Services\PlaidService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PlaidImportStrategy implements BankFeedImportInterface
{
    protected PlaidService $plaidService;

    public function __construct(PlaidService $plaidService)
    {
        $this->plaidService = $plaidService;
    }

    /**
     * Connect to Plaid with provided credentials.
     */
    public function connect(array $credentials): array
    {
        try {
            // For Plaid, credentials contain public_token from Link
            if (!isset($credentials['public_token'])) {
                return [
                    'success' => false,
                    'error' => 'Missing public_token in credentials',
                ];
            }

            // Exchange public token for access token
            $exchangeResult = $this->plaidService->exchangePublicToken($credentials['public_token']);
            
            if (!$exchangeResult['success']) {
                return [
                    'success' => false,
                    'error' => $exchangeResult['error'] ?? 'Failed to exchange public token',
                ];
            }

            return [
                'success' => true,
                'config' => [
                    'access_token' => $exchangeResult['access_token'],
                    'item_id' => $exchangeResult['item_id'],
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Plaid connection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to connect to Plaid: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Test the connection to Plaid.
     */
    public function testConnection(BankFeed $bankFeed): array
    {
        try {
            $config = $bankFeed->connection_config;
            
            if (!isset($config['access_token'])) {
                return [
                    'success' => false,
                    'error' => 'Missing access token in configuration',
                ];
            }

            // Test by fetching accounts
            $accounts = $this->plaidService->getAccounts($config['access_token']);
            
            return [
                'success' => true,
                'message' => 'Connection test successful',
                'accounts_count' => count($accounts),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get transactions from Plaid.
     */
    public function getTransactions(BankFeed $bankFeed, Carbon $startDate, Carbon $endDate): array
    {
        try {
            $config = $bankFeed->connection_config;
            
            if (!isset($config['access_token'])) {
                throw new \Exception('Missing access token in bank feed configuration');
            }

            $transactions = $this->plaidService->getTransactions(
                $config['access_token'],
                $startDate,
                $endDate
            );

            // Filter transactions for this specific account
            $accountTransactions = array_filter($transactions, function ($transaction) use ($bankFeed) {
                return $transaction['account_id'] === $bankFeed->source_account_id;
            });

            // Normalize transactions
            $normalizedTransactions = [];
            foreach ($accountTransactions as $transaction) {
                $normalizedTransactions[] = $this->normalizeTransaction($transaction, $bankFeed);
            }

            return $normalizedTransactions;
        } catch (\Exception $e) {
            Log::error('Failed to get Plaid transactions', [
                'bank_feed_id' => $bankFeed->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Update balance from Plaid.
     */
    public function updateBalance(BankFeed $bankFeed): array
    {
        try {
            $config = $bankFeed->connection_config;
            
            if (!isset($config['access_token'])) {
                throw new \Exception('Missing access token in bank feed configuration');
            }

            $accounts = $this->plaidService->getAccounts($config['access_token']);
            
            // Find the specific account
            $account = collect($accounts)->firstWhere('account_id', $bankFeed->source_account_id);
            
            if (!$account) {
                throw new \Exception('Account not found in Plaid response');
            }

            $currentBalance = $account['balances']['current'] ?? 0;
            $availableBalance = $account['balances']['available'] ?? $currentBalance;

            return [
                'current_balance_cents' => (int) round($currentBalance * 100),
                'available_balance_cents' => (int) round($availableBalance * 100),
                'balance_updated_at' => now(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to update Plaid balance', [
                'bank_feed_id' => $bankFeed->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Disconnect from Plaid.
     */
    public function disconnect(BankFeed $bankFeed): bool
    {
        try {
            // For Plaid, we might want to remove the item
            // For now, we'll just mark it as disconnected
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to disconnect from Plaid', [
                'bank_feed_id' => $bankFeed->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get display name.
     */
    public function getDisplayName(): string
    {
        return 'Plaid';
    }

    /**
     * Get configuration fields for Plaid.
     */
    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'public_token',
                'label' => 'Public Token',
                'type' => 'hidden', // This comes from Plaid Link
                'required' => true,
                'description' => 'Token obtained from Plaid Link',
            ],
        ];
    }

    /**
     * Validate Plaid credentials.
     */
    public function validateCredentials(array $credentials): array
    {
        if (!isset($credentials['public_token'])) {
            return [
                'success' => false,
                'errors' => ['public_token' => 'Public token is required'],
            ];
        }

        if (empty($credentials['public_token'])) {
            return [
                'success' => false,
                'errors' => ['public_token' => 'Public token cannot be empty'],
            ];
        }

        return [
            'success' => true,
        ];
    }

    /**
     * Normalize Plaid transaction data.
     */
    public function normalizeTransaction(array $sourceTransaction, BankFeed $bankFeed): array
    {
        return [
            'source_transaction_id' => $sourceTransaction['transaction_id'],
            'raw_data' => $sourceTransaction,
            'amount' => $sourceTransaction['amount'],
            'date' => $sourceTransaction['date'],
            'datetime' => $sourceTransaction['datetime'] ?? null,
            'description' => $sourceTransaction['name'],
            'category' => $sourceTransaction['category'][0] ?? null,
            'merchant_name' => $sourceTransaction['merchant_name'] ?? null,
            'status' => $sourceTransaction['pending'] ? 'pending' : 'cleared',
            'pending' => $sourceTransaction['pending'] ?? false,
            'pending_transaction_id' => $sourceTransaction['pending_transaction_id'] ?? null,
            'currency_code' => $sourceTransaction['iso_currency_code'] ?? 'USD',
            'metadata' => [
                'payment_channel' => $sourceTransaction['payment_channel'] ?? null,
                'transaction_type' => $sourceTransaction['transaction_type'] ?? null,
                'location' => $sourceTransaction['location'] ?? null,
                'category_id' => $sourceTransaction['category_id'] ?? null,
            ],
        ];
    }
}
