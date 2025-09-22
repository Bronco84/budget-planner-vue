<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidTransaction;
use App\Models\Transaction;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class PlaidService
{
    protected $client;
    protected $clientId;
    protected $secret;
    protected $environment;
    protected $baseUrl;
    protected $isConfigured = false;

    public function __construct()
    {
        $this->clientId = config('services.plaid.client_id');
        $this->secret = config('services.plaid.secret');
        $this->environment = config('services.plaid.environment', 'sandbox');

        // Validate configuration
        if (!$this->clientId || !$this->secret) {
            Log::error('Plaid configuration missing', [
                'client_id_set' => !empty($this->clientId),
                'secret_set' => !empty($this->secret),
                'environment' => $this->environment
            ]);
            return;
        }

        // Validate environment
        if (!in_array($this->environment, ['sandbox', 'production'])) {
            Log::error('Invalid Plaid environment', [
                'environment' => $this->environment,
                'valid_environments' => ['sandbox', 'production']
            ]);
            return;
        }

        $this->isConfigured = true;

        // Set the base URL based on environment
        $this->baseUrl = $this->environment === 'production'
            ? 'https://production.plaid.com'
            : 'https://sandbox.plaid.com';

        $this->initializeClient();
    }

    protected function initializeClient()
    {
        $options = [
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'PLAID-CLIENT-ID' => $this->clientId,
                'PLAID-SECRET' => $this->secret,
                'PLAID-ENV' => $this->environment,
            ],
            'connect_timeout' => 5,
            'timeout' => 30,
            'http_errors' => true, // Enable detailed HTTP error responses
        ];

        // Only disable SSL verification in local environment if explicitly configured
        if (app()->environment('local') && config('services.plaid.disable_ssl_verification', false)) {
            $options['verify'] = false;
        }

        // Add proxy configuration if set
        if ($proxy = config('services.plaid.proxy')) {
            $options['proxy'] = $proxy;
        }

        $this->client = new Client($options);
    }

    /**
     * Check if the service is properly configured
     */
    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    /**
     * Get the base URL for the current environment
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Create a link token for initializing Plaid Link
     */
    public function createLinkToken($userId, $existingAccessToken = null)
    {
        if (!$this->isConfigured) {
            throw new \Exception('Plaid service is not properly configured. Please check your environment settings.');
        }

        try {
            $payload = [
                'client_name' => config('app.name'),
                'user' => [
                    'client_user_id' => (string) $userId,
                    'email_address' => null, // Optional: Add user's email if available
                ],
                'products' => ['transactions'],
                'country_codes' => ['US'],
                'language' => 'en',
                'account_filters' => [
                    'depository' => [
                        'account_subtypes' => ['checking', 'savings']
                    ],
                    'credit' => [
                        'account_subtypes' => ['credit card']
                    ]
                ],
                'link_customization_name' => 'default',
            ];

            // If we have an existing access token, use update mode
            if ($existingAccessToken) {
                $payload['access_token'] = $existingAccessToken;
                $payload['update'] = [
                    'account_selection_enabled' => true
                ];
            }

            Log::debug('Creating Plaid link token', [
                'environment' => $this->environment,
                'client_name' => config('app.name'),
                'user_id' => $userId,
                'mode' => $existingAccessToken ? 'update' : 'create'
            ]);

            $response = $this->client->post('/link/token/create', [
                'json' => $payload
            ]);

            $result = json_decode($response->getBody(), true);

            if (!isset($result['link_token'])) {
                Log::error('Invalid Plaid response - missing link_token', [
                    'response' => $result
                ]);
                throw new \Exception('Invalid response from Plaid API');
            }

            return $result['link_token'];

        } catch (ConnectException $e) {
            Log::error('Plaid connection failed', [
                'error' => $e->getMessage(),
                'host' => $this->baseUrl,
                'environment' => $this->environment
            ]);

            $message = $this->getConnectionErrorMessage($e);
            throw new \Exception($message, 0, $e);

        } catch (RequestException $e) {
            $response = null;
            $errorDetails = null;

            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $errorDetails = json_decode($response->getBody(), true);
            }

            Log::error('Plaid link token creation failed', [
                'error' => $e->getMessage(),
                'status_code' => $response ? $response->getStatusCode() : null,
                'error_details' => $errorDetails,
                'environment' => $this->environment,
                'client_id_set' => !empty($this->clientId),
                'secret_set' => !empty($this->secret)
            ]);

            $errorMessage = 'Failed to initialize Plaid connection. ';
            if ($errorDetails && isset($errorDetails['error_message'])) {
                $errorMessage .= $errorDetails['error_message'];
            } elseif ($errorDetails && isset($errorDetails['display_message'])) {
                $errorMessage .= $errorDetails['display_message'];
            } else {
                $errorMessage .= 'Please check your configuration and try again.';
            }

            throw new \Exception($errorMessage, 0, $e);
        }
    }

    /**
     * Get a user-friendly connection error message
     */
    protected function getConnectionErrorMessage(\Exception $e): string
    {
        $message = 'Unable to connect to Plaid. ';

        if (str_contains($e->getMessage(), 'Could not resolve host')) {
            $message .= 'DNS resolution failed. Please check your internet connection and DNS settings. ';
            if (app()->environment('local')) {
                $message .= "Current environment: {$this->environment}, API host: " . parse_url($this->baseUrl, PHP_URL_HOST);
            }
        } elseif (str_contains($e->getMessage(), 'Connection timed out')) {
            $message .= 'Connection timed out. Please check your internet connection. ';
            if ($proxy = config('services.plaid.proxy')) {
                $message .= 'If you are using a proxy, please verify it is working correctly.';
            }
        } else {
            $message .= 'Please check your internet connection and try again.';
        }

        return $message;
    }

    /**
     * Get a user-friendly request error message
     */
    protected function getRequestErrorMessage(RequestException $e): string
    {
        if (!$e->hasResponse()) {
            return 'No response received from Plaid API.';
        }

        $response = json_decode($e->getResponse()->getBody(), true);
        return $response['error_message'] ?? 'An unexpected error occurred.';
    }

    /**
     * Exchange public token for access token
     */
    public function exchangePublicToken($publicToken)
    {
        if (!$this->isConfigured) {
            throw new \Exception('Plaid service is not properly configured');
        }

        try {
            $response = $this->client->post('/item/public_token/exchange', [
                'json' => [
                    'public_token' => $publicToken
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            return $result['access_token'] ?? null;
        } catch (RequestException $e) {
            Log::error('Plaid token exchange failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            throw new \Exception($this->getRequestErrorMessage($e));
        }
    }

    /**
     * Get transactions for a specific date range
     * @throws \Exception
     */
    public function getTransactions($accessToken, $startDate, $endDate)
    {
        if (!$this->isConfigured) {
            throw new \Exception('Plaid service is not properly configured');
        }

        try {
            $response = $this->client->post('/transactions/get', [
                'json' => [
                    'access_token' => $accessToken,
                    'start_date' => is_string($startDate) ? $startDate : $startDate->format('Y-m-d'),
                    'end_date' => is_string($endDate) ? $endDate : $endDate->format('Y-m-d'),
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            return $result['transactions'] ?? [];
        } catch (RequestException $e) {
            Log::error('Plaid transactions fetch failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            return [];
        }
    }

    /**
     * Get account details from Plaid
     */
    public function getAccounts(string $accessToken)
    {
        if (!$this->isConfigured) {
            throw new \Exception('Plaid service is not properly configured');
        }

        try {
            $response = $this->client->post('/accounts/get', [
                'json' => [
                    'access_token' => $accessToken,
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            return $result['accounts'] ?? [];
        } catch (RequestException $e) {
            Log::error('Plaid accounts fetch failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return [];
        }
    }

    /**
     * Link a Plaid account to an application account.
     *
     * @param Budget $budget
     * @param Account $account
     * @param string $accessToken
     * @param string $plaidAccountId
     * @param string|null $itemId
     * @param string|null $institutionName
     * @return PlaidAccount
     */
    public function linkAccount(
        Budget $budget,
        Account $account,
        string $accessToken,
        string $plaidAccountId,
        ?string $itemId = null,
        ?string $institutionName = null
    ): PlaidAccount {
        // Ensure we have a valid item ID
        if ($itemId === null || empty($itemId)) {
            $itemId = 'plaid-item-' . uniqid();
        }

        return PlaidAccount::updateOrCreate(
            [
                'plaid_account_id' => $plaidAccountId,
                'plaid_item_id' => $itemId,
            ],
            [
                'budget_id' => $budget->id,
                'account_id' => $account->id,
                'institution_name' => $institutionName,
                'access_token' => $accessToken,
            ]
        );
    }

    /**
     * Sync transactions from Plaid to the application.
     *
     * @param PlaidAccount $plaidAccount
     * @param int $days Number of days in the past to sync
     * @return array
     */
    public function syncTransactions(PlaidAccount $plaidAccount, int $days = 120): array
    {
        $endDate = Carbon::today();
        $startDate = Carbon::today()->subDays($days);

        if (!$plaidAccount->access_token) {
            Log::error('Sync transactions failed: Access token is null', [
                'plaid_account_id' => $plaidAccount->id,
                'plaid_item_id' => $plaidAccount->plaid_item_id
            ]);

            return [
                'imported' => 0,
                'updated' => 0,
            ];
        }

        try {
            $transactions = $this->getTransactions(
                $plaidAccount->access_token,
                $startDate,
                $endDate
            );

            if (empty($transactions)) {
                return [
                    'imported' => 0,
                    'updated' => 0,
                ];
            }

            $imported = 0;
            $updated = 0;

            foreach ($transactions as $transaction) {
                // Only process transactions for the linked account
                if ($transaction['account_id'] !== $plaidAccount->plaid_account_id) {
                    continue;
                }

                // Store or update the Plaid transaction
                $plaidTransaction = PlaidTransaction::updateOrCreate(
                    [
                        'plaid_transaction_id' => $transaction['transaction_id'],
                    ],
                    [
                        'account_id' => $plaidAccount->account_id,
                        'plaid_account_id' => $transaction['account_id'],
                        'pending' => $transaction['pending'] ?? false,
                        'amount' => $transaction['amount'],
                        'date' => $transaction['date'],
                        'datetime' => $transaction['datetime'] ?? null,
                        'authorized_date' => $transaction['authorized_date'] ?? null,
                        'authorized_datetime' => $transaction['authorized_datetime'] ?? null,
                        'name' => $transaction['name'],
                        'merchant_name' => $transaction['merchant_name'] ?? null,
                        'merchant_entity_id' => $transaction['merchant_entity_id'] ?? null,
                        'payment_channel' => $transaction['payment_channel'] ?? null,
                        'transaction_code' => $transaction['transaction_code'] ?? null,
                        'transaction_type' => $transaction['transaction_type'] ?? null,
                        'pending_transaction_id' => $transaction['pending_transaction_id'] ?? null,
                        'iso_currency_code' => $transaction['iso_currency_code'] ?? null,
                        'unofficial_currency_code' => $transaction['unofficial_currency_code'] ?? null,
                        'check_number' => $transaction['check_number'] ?? null,
                        'category' => $transaction['category'][0] ?? null,
                        'counterparties' => isset($transaction['counterparties']) ? json_encode($transaction['counterparties']) : null,
                        'location' => isset($transaction['location']) ? json_encode($transaction['location']) : null,
                        'payment_meta' => isset($transaction['payment_meta']) ? json_encode($transaction['payment_meta']) : null,
                        'personal_finance_category' => isset($transaction['personal_finance_category']) ? json_encode($transaction['personal_finance_category']) : null,
                        'personal_finance_category_icon_url' => $transaction['personal_finance_category_icon_url'] ?? null,
                        'logo_url' => $transaction['logo_url'] ?? null,
                        'website' => $transaction['website'] ?? null,
                        'metadata' => isset($transaction['metadata']) ? json_encode($transaction['metadata']) : null,
                        'original_data' => json_encode($transaction),
                    ]
                );

                // Check if we already have a transaction for this Plaid transaction
                $existingTransaction = Transaction::where('plaid_transaction_id', $transaction['transaction_id'])->first();

                if ($existingTransaction) {
                    // Update existing transaction
                    $existingTransaction->update([
                        'description' => $transaction['name'],
                        'category' => $transaction['category'][0] ?? 'Uncategorized',
                        'amount_in_cents' => -1 * $transaction['amount'] * 100, // Negate amount as Plaid uses positive for expenses
                        'date' => $transaction['date'],
                    ]);

                    $updated++;
                } else {
                    // Create new transaction
                    Transaction::create([
                        'budget_id' => $plaidAccount->budget_id,
                        'account_id' => $plaidAccount->account_id,
                        'description' => $transaction['name'],
                        'category' => $transaction['category'][0] ?? 'Uncategorized',
                        'amount_in_cents' => -1 * $transaction['amount'] * 100, // Negate amount as Plaid uses positive for expenses
                        'date' => $transaction['date'],
                        'plaid_transaction_id' => $transaction['transaction_id'],
                        'is_plaid_imported' => true,
                    ]);

                    $imported++;
                }

                // Check if this transaction was previously pending and handle accordingly
                if (isset($transaction['pending_transaction_id']) && !empty($transaction['pending_transaction_id'])) {
                    // This is a settled transaction that was previously pending
                    // Find the transaction linked to the pending plaid transaction
                    $pendingTransaction = Transaction::where('plaid_transaction_id', $transaction['pending_transaction_id'])
                        ->first();

                    if ($pendingTransaction) {
                        // Delete the pending transaction since it's now settled
                        $pendingTransaction->delete();

                        // Log the deletion for debugging
                        Log::info('Deleted pending transaction after settlement', [
                            'pending_transaction_id' => $transaction['pending_transaction_id'],
                            'settled_transaction_id' => $transaction['transaction_id']
                        ]);
                    }
                }
            }

            // Update last sync timestamp
            $plaidAccount->update([
                'last_sync_at' => now()
            ]);

            // Update account balance
            $this->updateAccountBalance($plaidAccount);

            return [
                'imported' => $imported,
                'updated' => $updated,
            ];
        } catch (\Exception $e) {
            Log::error('Error syncing transactions', [
                'message' => $e->getMessage(),
                'plaid_account_id' => $plaidAccount->id,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'imported' => 0,
                'updated' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update the account balance from Plaid.
     *
     * @param PlaidAccount $plaidAccount
     * @return bool
     */
    public function updateAccountBalance(PlaidAccount $plaidAccount): bool
    {
        if (!$plaidAccount->access_token) {
            Log::error('Update account balance failed: Access token is null', [
                'plaid_account_id' => $plaidAccount->id,
                'plaid_item_id' => $plaidAccount->plaid_item_id
            ]);

            return false;
        }

        try {
            $accounts = $this->getAccounts($plaidAccount->access_token);

            if (empty($accounts)) {
                return false;
            }

            foreach ($accounts as $account) {
                if ($account['account_id'] === $plaidAccount->plaid_account_id) {
                    $plaidAccount->update([
                        'current_balance_cents' => $account['balances']['current'] * 100,
                        'available_balance_cents' => ($account['balances']['available'] ?? $account['balances']['current']) * 100,
                        'balance_updated_at' => now(),
                    ]);

                    // Also update the associated account
                    $linkedAccount = $plaidAccount->account;
                    if ($linkedAccount) {
                        $linkedAccount->update([
                            'current_balance_cents' => $account['balances']['current'] * 100,
                            'balance_updated_at' => now(),
                        ]);
                    }

                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Error updating account balance', [
                'message' => $e->getMessage(),
                'plaid_account_id' => $plaidAccount->id,
                'trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }
}
