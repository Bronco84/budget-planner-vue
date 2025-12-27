<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\PlaidStatementHistory;
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
     * @throws \Exception
     */
    public function createLinkToken($userId, $existingAccessToken = null)
    {
        if (!$this->isConfigured) {
            throw new \Exception('Plaid service is not properly configured. Please check your environment settings.');
        }

        try {
            // Start with base products - 'transactions' is always needed
            // We'll add 'liabilities' conditionally based on the connection
            $products = ['transactions'];

            // Only request liabilities for update mode (existing connections)
            // For new connections, liabilities will be requested via update mode if needed
            if ($existingAccessToken) {
                // Check if this connection has any credit card accounts that would benefit from liabilities
                $hasLiabilityAccounts = PlaidAccount::whereHas('plaidConnection', function ($query) use ($existingAccessToken) {
                    $query->where('access_token', $existingAccessToken);
                })
                ->where(function ($query) {
                    $query->where('account_type', 'credit')
                          ->orWhere('account_type', 'loan')
                          ->orWhere('account_type', 'mortgage');
                })
                ->exists();

                if ($hasLiabilityAccounts) {
                    $products[] = 'liabilities';
                }
            }

            $payload = [
                'client_name' => config('app.name'),
                'user' => [
                    'client_user_id' => (string) $userId,
                    'email_address' => null, // Optional: Add user's email if available
                ],
                'products' => $products,
                'country_codes' => ['US'],
                'language' => 'en',
                // Remove account_filters to allow ALL account types
                // 'account_filters' => [...],
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
                'mode' => $existingAccessToken ? 'update' : 'create',
                'products' => $payload['products'],
                'account_filters_removed' => true
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
            $response = null;
            $errorDetails = null;

            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $errorDetails = json_decode($response->getBody(), true);
            }

            Log::error('Plaid transactions fetch failed', [
                'error' => $e->getMessage(),
                'response' => $errorDetails,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // If it's a PRODUCT_NOT_READY error, throw a specific exception
            if ($errorDetails && isset($errorDetails['error_code']) && $errorDetails['error_code'] === 'PRODUCT_NOT_READY') {
                throw new \Exception('PRODUCT_NOT_READY: ' . ($errorDetails['error_message'] ?? 'Transaction data is not yet ready'));
            }

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
            $accounts = $result['accounts'] ?? [];

            // Log detailed account information for debugging
            Log::info('Plaid accounts retrieved', [
                'total_accounts' => count($accounts),
                'accounts_summary' => array_map(function ($account) {
                    return [
                        'account_id' => $account['account_id'],
                        'name' => $account['name'],
                        'type' => $account['type'],
                        'subtype' => $account['subtype'] ?? 'no_subtype',
                        'mask' => $account['mask'] ?? 'no_mask',
                        'balance' => $account['balances']['current'] ?? 'no_balance'
                    ];
                }, $accounts)
            ]);

            return $accounts;
        } catch (RequestException $e) {
            Log::error('Plaid accounts fetch failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return [];
        }
    }

    /**
     * Get liabilities (loans, mortgages, credit cards) from Plaid
     */
    public function getLiabilities(string $accessToken)
    {
        if (!$this->isConfigured) {
            throw new \Exception('Plaid service is not properly configured');
        }

        try {
            $response = $this->client->post('/liabilities/get', [
                'json' => [
                    'access_token' => $accessToken,
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            
            Log::info('Plaid liabilities retrieved', [
                'credit_cards' => count($result['liabilities']['credit'] ?? []),
                'mortgages' => count($result['liabilities']['mortgage'] ?? []),
                'student_loans' => count($result['liabilities']['student'] ?? []),
                'other_liabilities' => count($result['liabilities']['other'] ?? []),
                'raw_data' => $result
            ]);
            
            return $result['liabilities'] ?? [];
        } catch (RequestException $e) {
            Log::error('Plaid liabilities fetch failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return [];
        }
    }

    /**
     * Create or find a PlaidConnection for the given item.
     *
     * @param Budget $budget
     * @param string $accessToken
     * @param string $itemId
     * @param string|null $institutionId
     * @param string|null $institutionName
     * @return PlaidConnection
     */
    public function createOrFindConnection(
        Budget $budget,
        string $accessToken,
        string $itemId,
        ?string $institutionId = null,
        ?string $institutionName = null
    ): PlaidConnection {
        return PlaidConnection::updateOrCreate(
            [
                'budget_id' => $budget->id,
                'plaid_item_id' => $itemId,
            ],
            [
                'institution_id' => $institutionId,
                'institution_name' => $institutionName ?? 'Unknown Institution',
                'access_token' => $accessToken,
                'status' => PlaidConnection::STATUS_ACTIVE,
                'error_message' => null,
                'last_sync_at' => null,
            ]
        );
    }

    /**
     * Find an existing active PlaidConnection for the given institution.
     *
     * @param Budget $budget
     * @param string $institutionName
     * @return PlaidConnection|null
     */
    public function findConnectionByInstitution(Budget $budget, string $institutionName): ?PlaidConnection
    {
        return PlaidConnection::forInstitution($budget, $institutionName)->first();
    }

    /**
     * Link multiple Plaid accounts to budget accounts under a single connection.
     *
     * @param Budget $budget
     * @param array $accountData Array of [local_account, plaid_account_data] pairs
     * @param string $accessToken
     * @param string $itemId
     * @param string|null $institutionId
     * @param string|null $institutionName
     * @return array Array of created PlaidAccount records
     */
    public function linkMultipleAccounts(
        Budget $budget,
        array $accountData,
        string $accessToken,
        string $itemId,
        ?string $institutionId = null,
        ?string $institutionName = null
    ): array {
        // Create or find the PlaidConnection
        $plaidConnection = $this->createOrFindConnection(
            $budget,
            $accessToken,
            $itemId,
            $institutionId,
            $institutionName
        );

        $plaidAccounts = [];
        foreach ($accountData as $data) {
            $localAccount = $data['local_account'];
            $plaidAccountData = $data['plaid_account_data'];

            $plaidAccounts[] = $this->linkAccountToConnection(
                $plaidConnection,
                $localAccount,
                $plaidAccountData
            );
        }

        return $plaidAccounts;
    }

    /**
     * Link a single Plaid account to a connection.
     *
     * @param PlaidConnection $plaidConnection
     * @param Account $account
     * @param array $plaidAccountData
     * @return PlaidAccount
     */
    public function linkAccountToConnection(
        PlaidConnection $plaidConnection,
        Account $account,
        array $plaidAccountData
    ): PlaidAccount {
        // Update the local account with Plaid data
        $accountType = $this->mapPlaidAccountType($plaidAccountData);
        $account->update([
            'type' => $accountType,
            'current_balance_cents' => isset($plaidAccountData['balances']['current'])
                ? (int) round($plaidAccountData['balances']['current'] * 100)
                : $account->current_balance_cents,
            'balance_updated_at' => now(),
        ]);

        // Create or update the PlaidAccount record
        return PlaidAccount::updateOrCreate(
            [
                'plaid_account_id' => $plaidAccountData['account_id'],
                'plaid_connection_id' => $plaidConnection->id,
            ],
            [
                'account_id' => $account->id,
                'account_name' => $plaidAccountData['name'] ?? 'Unknown Account',
                'account_type' => $plaidAccountData['type'] ?? null,
                'account_subtype' => $plaidAccountData['subtype'] ?? null,
                'account_mask' => $plaidAccountData['mask'] ?? null,
                'current_balance_cents' => isset($plaidAccountData['balances']['current'])
                    ? (int) round($plaidAccountData['balances']['current'] * 100)
                    : 0,
                'available_balance_cents' => isset($plaidAccountData['balances']['available'])
                    ? (int) round($plaidAccountData['balances']['available'] * 100)
                    : null,
                'balance_updated_at' => now(),
            ]
        );
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

        // Get the account data from Plaid
        $plaidAccounts = $this->getAccounts($accessToken);
        $plaidAccountData = collect($plaidAccounts)->firstWhere('account_id', $plaidAccountId);

        if (!$plaidAccountData) {
            throw new \Exception("Plaid account {$plaidAccountId} not found");
        }

        // Create or find the PlaidConnection
        $plaidConnection = $this->createOrFindConnection(
            $budget,
            $accessToken,
            $itemId,
            null, // We don't have institution_id in legacy calls
            $institutionName
        );

        // Link the account to the connection
        return $this->linkAccountToConnection($plaidConnection, $account, $plaidAccountData);
    }

    /**
     * Map Plaid account type and subtype to a readable format
     */
    public function mapPlaidAccountType(array $plaidAccount): string
    {
        $type = $plaidAccount['type'] ?? 'other';
        $subtype = $plaidAccount['subtype'] ?? '';

        // If there's a subtype, use it for more specific typing
        if (!empty($subtype)) {
            // Common mappings for better readability
            $subtypeMap = [
                'checking' => 'checking',
                'savings' => 'savings',
                'credit card' => 'credit card',
                'cd' => 'certificate of deposit',
                'money market' => 'money market',
                'hsa' => 'health savings account',
                'ira' => 'traditional ira',
                'roth' => 'roth ira',
                '401k' => '401k',
                '403B' => '403b',
                '457b' => '457b',
                'brokerage' => 'brokerage',
                'mutual fund' => 'mutual fund',
                'mortgage' => 'mortgage',
                'auto' => 'auto loan',
                'student' => 'student loan',
                'home equity' => 'home equity',
                'line of credit' => 'line of credit',
                'business' => 'business loan',
                'paypal' => 'paypal',
                'prepaid' => 'prepaid',
            ];

            return $subtypeMap[$subtype] ?? $subtype;
        }

        // Fallback to main type if no subtype
        return $type;
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

        $accessToken = $plaidAccount->plaidConnection->access_token ?? null;

        if (!$accessToken) {
            Log::error('Sync transactions failed: Access token is null', [
                'plaid_account_id' => $plaidAccount->id,
                'connection_id' => $plaidAccount->plaid_connection_id
            ]);

            return [
                'imported' => 0,
                'updated' => 0,
            ];
        }

        try {
            $transactions = $this->getTransactions(
                $accessToken,
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
                        // Pass arrays directly - the model's 'array' casts will handle JSON encoding
                        'counterparties' => $transaction['counterparties'] ?? null,
                        'location' => $transaction['location'] ?? null,
                        'payment_meta' => $transaction['payment_meta'] ?? null,
                        'personal_finance_category' => $transaction['personal_finance_category'] ?? null,
                        'personal_finance_category_icon_url' => $transaction['personal_finance_category_icon_url'] ?? null,
                        'logo_url' => $transaction['logo_url'] ?? null,
                        'website' => $transaction['website'] ?? null,
                        'metadata' => $transaction['metadata'] ?? null,
                        'original_data' => $transaction,
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
                        'budget_id' => $plaidAccount->account->budget_id,
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
            $plaidAccount->plaidConnection->markSynced();

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
        $accessToken = $plaidAccount->plaidConnection->access_token ?? null;

        if (!$accessToken) {
            Log::error('Update account balance failed: Access token is null', [
                'plaid_account_id' => $plaidAccount->id,
                'connection_id' => $plaidAccount->plaid_connection_id
            ]);

            return false;
        }

        try {
            $accounts = $this->getAccounts($accessToken);

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

                    // Update liability data for credit cards (gracefully handle errors)
                    if ($plaidAccount->isCreditCard()) {
                        try {
                            $this->updateLiabilityData($plaidAccount);
                        } catch (\Exception $liabilityError) {
                            // Log the error but don't fail the balance update
                            Log::warning('Failed to update liability data during balance sync', [
                                'plaid_account_id' => $plaidAccount->id,
                                'error' => $liabilityError->getMessage()
                            ]);
                        }
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

    /**
     * Update liability data (statement balance, payment info) for a credit card account.
     *
     * @param PlaidAccount $plaidAccount
     * @return bool
     */
    public function updateLiabilityData(PlaidAccount $plaidAccount): bool
    {
        // Only process credit card accounts
        if (!$plaidAccount->isCreditCard()) {
            Log::debug('Skipping liability update for non-credit card account', [
                'plaid_account_id' => $plaidAccount->id,
                'account_type' => $plaidAccount->account_type,
                'account_subtype' => $plaidAccount->account_subtype
            ]);
            return false;
        }

        $accessToken = $plaidAccount->plaidConnection->access_token ?? null;

        if (!$accessToken) {
            Log::error('Update liability data failed: Access token is null', [
                'plaid_account_id' => $plaidAccount->id,
                'connection_id' => $plaidAccount->plaid_connection_id
            ]);
            return false;
        }

        try {
            // Get liability data from Plaid (this returns ALL credit cards for the connection)
            $liabilities = $this->getLiabilities($accessToken);

            if (empty($liabilities)) {
                Log::info('No liability data returned from Plaid', [
                    'plaid_account_id' => $plaidAccount->id
                ]);
                return false;
            }

            $creditCards = $liabilities['credit'] ?? [];

            if (empty($creditCards)) {
                Log::info('No credit cards found in liabilities data', [
                    'plaid_account_id' => $plaidAccount->id
                ]);
                return false;
            }

            // OPTIMIZATION: Update ALL credit cards from this connection, not just the one passed in
            // This prevents redundant API calls when multiple cards exist on the same connection
            $allConnectionCards = PlaidAccount::where('plaid_connection_id', $plaidAccount->plaid_connection_id)
                ->where('account_type', 'credit')
                ->where('account_subtype', 'credit card')
                ->get();

            $updatedCount = 0;
            $matchingCard = null;

            foreach ($creditCards as $card) {
                // Find the PlaidAccount that matches this card
                $matchingPlaidAccount = $allConnectionCards->firstWhere('plaid_account_id', $card['account_id']);

                if (!$matchingPlaidAccount) {
                    continue;
                }

                // Track if we updated the card that was originally passed in
                if ($matchingPlaidAccount->id === $plaidAccount->id) {
                    $matchingCard = $card;
                }

                // Update this credit card's liability data
                $this->updateSingleCardLiabilityData($matchingPlaidAccount, $card);
                $updatedCount++;
            }

            Log::info('Updated liability data for multiple credit cards', [
                'connection_id' => $plaidAccount->plaid_connection_id,
                'updated_count' => $updatedCount,
                'total_cards' => count($creditCards)
            ]);

            if (!$matchingCard) {
                Log::info('No matching credit card found for the requested account', [
                    'plaid_account_id' => $plaidAccount->plaid_account_id,
                    'credit_cards_count' => count($creditCards)
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Error updating liability data', [
                'message' => $e->getMessage(),
                'plaid_account_id' => $plaidAccount->id,
                'trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }

    /**
     * Update liability data for a single credit card.
     *
     * @param PlaidAccount $plaidAccount
     * @param array $cardData
     * @return void
     */
    private function updateSingleCardLiabilityData(PlaidAccount $plaidAccount, array $cardData): void
    {
        // Extract liability data
        $statementBalance = $cardData['last_statement_balance'] ?? null;
        $statementIssueDate = $cardData['last_statement_issue_date'] ?? null;
        $creditLimit = $cardData['credit_limit'] ?? null;

        // Update PlaidAccount with liability fields
        $plaidAccount->update([
            'last_statement_balance_cents' => $statementBalance !== null ? (int) round($statementBalance * 100) : null,
            'last_statement_issue_date' => $statementIssueDate,
            'last_payment_amount_cents' => isset($cardData['last_payment_amount'])
                ? (int) round($cardData['last_payment_amount'] * 100)
                : null,
            'last_payment_date' => $cardData['last_payment_date'] ?? null,
            'next_payment_due_date' => $cardData['next_payment_due_date'] ?? null,
            'minimum_payment_amount_cents' => isset($cardData['minimum_payment_amount'])
                ? (int) round($cardData['minimum_payment_amount'] * 100)
                : null,
            'apr_percentage' => $cardData['apr'] ?? null,
            'credit_limit_cents' => $creditLimit !== null ? (int) round($creditLimit * 100) : null,
            'liability_updated_at' => now(),
        ]);

        // Create statement history record if we have a new statement
        if ($statementBalance !== null && $statementIssueDate !== null) {
            $statementBalanceCents = (int) round($statementBalance * 100);
            $creditLimitCents = $creditLimit !== null ? (int) round($creditLimit * 100) : null;

            // Calculate credit utilization
            $creditUtilization = PlaidStatementHistory::calculateCreditUtilization(
                $statementBalanceCents,
                $creditLimitCents
            );

            // Check if we already have this statement in history
            $existingHistory = PlaidStatementHistory::where('plaid_account_id', $plaidAccount->id)
                ->where('statement_issue_date', $statementIssueDate)
                ->first();

            if (!$existingHistory) {
                PlaidStatementHistory::create([
                    'plaid_account_id' => $plaidAccount->id,
                    'statement_balance_cents' => $statementBalanceCents,
                    'statement_issue_date' => $statementIssueDate,
                    'payment_due_date' => $cardData['next_payment_due_date'] ?? null,
                    'minimum_payment_cents' => isset($cardData['minimum_payment_amount'])
                        ? (int) round($cardData['minimum_payment_amount'] * 100)
                        : null,
                    'apr_percentage' => $cardData['apr'] ?? null,
                    'credit_utilization_percentage' => $creditUtilization,
                ]);

                Log::info('Created new statement history record', [
                    'plaid_account_id' => $plaidAccount->id,
                    'statement_issue_date' => $statementIssueDate,
                    'credit_utilization' => $creditUtilization
                ]);
            }
        }

        Log::info('Successfully updated liability data for single card', [
            'plaid_account_id' => $plaidAccount->id,
            'plaid_account_identifier' => $plaidAccount->plaid_account_id,
            'statement_balance' => $statementBalance,
            'statement_date' => $statementIssueDate
        ]);
    }
}
