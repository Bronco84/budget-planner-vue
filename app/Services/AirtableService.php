<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class AirtableService
{
    protected $client;
    protected $apiKey;
    protected $baseId;
    protected $baseUrl;
    protected $accountsTable;
    protected $transactionsTable;
    protected $isConfigured = false;

    public function __construct()
    {
        $this->apiKey = config('services.airtable.api_key');
        $this->baseId = config('services.airtable.base_id');
        $this->baseUrl = config('services.airtable.base_url');
        $this->accountsTable = config('services.airtable.accounts_table');
        $this->transactionsTable = config('services.airtable.transactions_table');

        // Validate configuration
        if (!$this->apiKey || !$this->baseId) {
            Log::error('Airtable configuration missing', [
                'api_key_set' => !empty($this->apiKey),
                'base_id_set' => !empty($this->baseId),
            ]);
            return;
        }

        $this->isConfigured = true;
        $this->initializeClient();
    }

    protected function initializeClient()
    {
        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Check if the service is properly configured
     */
    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    /**
     * Get all accounts from Airtable
     */
    public function getAccounts(?string $filterByFormula = null, ?array $fields = null, int $maxRecords = 100): Collection
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        try {
            $params = [
                'maxRecords' => $maxRecords,
            ];

            if ($filterByFormula) {
                $params['filterByFormula'] = $filterByFormula;
            }

            if ($fields) {
                $params['fields'] = $fields;
            }

            $url = "{$this->baseId}/{$this->accountsTable}";
            $response = $this->client->get($url, ['query' => $params]);

            $result = json_decode($response->getBody(), true);
            
            Log::info('Fetched accounts from Airtable', [
                'count' => count($result['records'] ?? []),
                'has_offset' => isset($result['offset'])
            ]);

            return collect($result['records'] ?? []);
        } catch (RequestException $e) {
            Log::error('Airtable accounts fetch failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return collect([]);
        }
    }

    /**
     * Get all transactions from Airtable
     */
    public function getTransactions(?string $filterByFormula = null, ?array $fields = null, int $maxRecords = 100): Collection
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        try {
            $params = [
                'maxRecords' => $maxRecords,
            ];

            if ($filterByFormula) {
                $params['filterByFormula'] = $filterByFormula;
            }

            if ($fields) {
                $params['fields'] = $fields;
            }

            $url = "{$this->baseId}/{$this->transactionsTable}";
            $response = $this->client->get($url, ['query' => $params]);

            $result = json_decode($response->getBody(), true);
            
            Log::info('Fetched transactions from Airtable', [
                'count' => count($result['records'] ?? []),
                'has_offset' => isset($result['offset'])
            ]);

            return collect($result['records'] ?? []);
        } catch (RequestException $e) {
            Log::error('Airtable transactions fetch failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return collect([]);
        }
    }

    /**
     * Get paginated records from any table
     */
    public function getAllRecords(string $tableName, ?string $filterByFormula = null, ?array $fields = null): Collection
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        $allRecords = collect([]);
        $offset = null;

        do {
            try {
                $params = [
                    'maxRecords' => 100, // Airtable's max per request
                ];

                if ($filterByFormula) {
                    $params['filterByFormula'] = $filterByFormula;
                }

                if ($fields) {
                    $params['fields'] = $fields;
                }

                if ($offset) {
                    $params['offset'] = $offset;
                }

                $url = "{$this->baseId}/{$tableName}";
                $response = $this->client->get($url, ['query' => $params]);

                $result = json_decode($response->getBody(), true);
                
                $records = collect($result['records'] ?? []);
                $allRecords = $allRecords->concat($records);
                
                $offset = $result['offset'] ?? null;

                Log::debug('Fetched batch from Airtable', [
                    'table' => $tableName,
                    'batch_count' => $records->count(),
                    'total_count' => $allRecords->count(),
                    'has_more' => !is_null($offset)
                ]);

            } catch (RequestException $e) {
                Log::error('Airtable batch fetch failed', [
                    'table' => $tableName,
                    'error' => $e->getMessage(),
                    'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
                ]);
                break;
            }
        } while ($offset);

        return $allRecords;
    }

    /**
     * Get a single record from any table by ID
     */
    public function getRecord(string $tableName, string $recordId): ?array
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        try {
            $url = "{$this->baseId}/{$tableName}/{$recordId}";
            $response = $this->client->get($url);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            }

            return null;
        } catch (\Exception $e) {
            \Log::error('Failed to get Airtable record', [
                'table' => $tableName,
                'record_id' => $recordId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get account by ID
     */
    public function getAccount(string $recordId): ?array
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        try {
            $url = "{$this->baseId}/{$this->accountsTable}/{$recordId}";
            $response = $this->client->get($url);

            $result = json_decode($response->getBody(), true);
            return $result;
        } catch (RequestException $e) {
            Log::error('Airtable account fetch failed', [
                'record_id' => $recordId,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return null;
        }
    }

    /**
     * Get transaction by ID
     */
    public function getTransaction(string $recordId): ?array
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        try {
            $url = "{$this->baseId}/{$this->transactionsTable}/{$recordId}";
            $response = $this->client->get($url);

            $result = json_decode($response->getBody(), true);
            return $result;
        } catch (RequestException $e) {
            Log::error('Airtable transaction fetch failed', [
                'record_id' => $recordId,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return null;
        }
    }

    /**
     * Get schema information for accounts table
     */
    public function getAccountsSchema(): ?array
    {
        return $this->getTableSchema($this->accountsTable);
    }

    /**
     * Get schema information for transactions table
     */
    public function getTransactionsSchema(): ?array
    {
        return $this->getTableSchema($this->transactionsTable);
    }

    /**
     * Get schema information for any table (by fetching first record and analyzing fields)
     */
    public function getTableSchema(string $tableName): ?array
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        try {
            $url = "{$this->baseId}/{$tableName}";
            $response = $this->client->get($url, [
                'query' => ['maxRecords' => 1]
            ]);

            $result = json_decode($response->getBody(), true);
            
            if (empty($result['records'])) {
                return ['fields' => [], 'sample_record' => null];
            }

            $firstRecord = $result['records'][0];
            $fields = array_keys($firstRecord['fields'] ?? []);
            
            return [
                'fields' => $fields,
                'sample_record' => $firstRecord,
                'table_name' => $tableName
            ];
        } catch (RequestException $e) {
            Log::error('Airtable schema fetch failed', [
                'table' => $tableName,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? json_decode($e->getResponse()->getBody(), true) : null
            ]);
            return null;
        }
    }

    /**
     * Compare Airtable data structure with current Plaid implementation
     */
    public function analyzeDataStructure(): array
    {
        if (!$this->isConfigured) {
            throw new \Exception('Airtable service is not properly configured');
        }

        $analysis = [
            'accounts' => [
                'schema' => null,
                'sample_count' => 0,
                'fields_analysis' => []
            ],
            'transactions' => [
                'schema' => null,
                'sample_count' => 0,
                'fields_analysis' => []
            ],
            'comparison_with_plaid' => [
                'accounts' => [],
                'transactions' => []
            ]
        ];

        try {
            // Analyze accounts
            $accountsSchema = $this->getAccountsSchema();
            if ($accountsSchema) {
                $analysis['accounts']['schema'] = $accountsSchema;
                
                $sampleAccounts = $this->getAccounts(null, null, 5);
                $analysis['accounts']['sample_count'] = $sampleAccounts->count();
                
                if ($sampleAccounts->isNotEmpty()) {
                    $analysis['accounts']['sample_data'] = $sampleAccounts->take(2)->toArray();
                }
            }

            // Analyze transactions
            $transactionsSchema = $this->getTransactionsSchema();
            if ($transactionsSchema) {
                $analysis['transactions']['schema'] = $transactionsSchema;
                
                $sampleTransactions = $this->getTransactions(null, null, 5);
                $analysis['transactions']['sample_count'] = $sampleTransactions->count();
                
                if ($sampleTransactions->isNotEmpty()) {
                    $analysis['transactions']['sample_data'] = $sampleTransactions->take(2)->toArray();
                }
            }

            // Add field mapping suggestions
            $analysis['field_mappings'] = $this->suggestFieldMappings($accountsSchema, $transactionsSchema);

        } catch (\Exception $e) {
            Log::error('Airtable data structure analysis failed', [
                'error' => $e->getMessage()
            ]);
            $analysis['error'] = $e->getMessage();
        }

        return $analysis;
    }

    /**
     * Suggest field mappings between Airtable and current app structure
     */
    protected function suggestFieldMappings(?array $accountsSchema, ?array $transactionsSchema): array
    {
        $mappings = [
            'accounts' => [],
            'transactions' => []
        ];

        // Map account fields
        if ($accountsSchema && isset($accountsSchema['fields'])) {
            $plaidAccountFields = [
                'plaid_account_id', 'institution_name', 'current_balance_cents', 
                'available_balance_cents', 'account_type', 'account_subtype'
            ];
            
            foreach ($accountsSchema['fields'] as $airtableField) {
                $suggestions = $this->findSimilarFields($airtableField, $plaidAccountFields);
                if (!empty($suggestions)) {
                    $mappings['accounts'][$airtableField] = $suggestions;
                }
            }
        }

        // Map transaction fields
        if ($transactionsSchema && isset($transactionsSchema['fields'])) {
            $plaidTransactionFields = [
                'plaid_transaction_id', 'amount', 'date', 'name', 'merchant_name',
                'category', 'payment_channel', 'transaction_type', 'pending'
            ];
            
            foreach ($transactionsSchema['fields'] as $airtableField) {
                $suggestions = $this->findSimilarFields($airtableField, $plaidTransactionFields);
                if (!empty($suggestions)) {
                    $mappings['transactions'][$airtableField] = $suggestions;
                }
            }
        }

        return $mappings;
    }

    /**
     * Find similar field names using basic string similarity
     */
    protected function findSimilarFields(string $targetField, array $candidateFields): array
    {
        $suggestions = [];
        $targetLower = strtolower($targetField);
        
        foreach ($candidateFields as $candidate) {
            $candidateLower = strtolower($candidate);
            
            // Exact match
            if ($targetLower === $candidateLower) {
                $suggestions[] = ['field' => $candidate, 'confidence' => 'exact'];
                continue;
            }
            
            // Contains match
            if (str_contains($targetLower, $candidateLower) || str_contains($candidateLower, $targetLower)) {
                $suggestions[] = ['field' => $candidate, 'confidence' => 'high'];
                continue;
            }
            
            // Levenshtein distance for similarity
            $distance = levenshtein($targetLower, $candidateLower);
            $maxLen = max(strlen($targetLower), strlen($candidateLower));
            $similarity = 1 - ($distance / $maxLen);
            
            if ($similarity > 0.6) {
                $confidence = $similarity > 0.8 ? 'high' : 'medium';
                $suggestions[] = ['field' => $candidate, 'confidence' => $confidence, 'similarity' => round($similarity, 2)];
            }
        }
        
        return $suggestions;
    }
}
