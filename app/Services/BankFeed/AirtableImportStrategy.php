<?php

namespace App\Services\BankFeed;

use App\Models\BankFeed;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class AirtableImportStrategy implements BankFeedImportInterface
{
    protected Client $client;
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.airtable.base_url', 'https://api.airtable.com/v0');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    /**
     * Connect to Airtable with provided credentials.
     */
    public function connect(array $credentials): array
    {
        try {
            // Validate required credentials
            $required = ['api_key', 'base_id', 'table_name'];
            foreach ($required as $field) {
                if (!isset($credentials[$field]) || empty($credentials[$field])) {
                    return [
                        'success' => false,
                        'error' => "Missing required field: {$field}",
                    ];
                }
            }

            // Test the connection by fetching table schema
            $testResult = $this->testAirtableConnection($credentials);
            
            if (!$testResult['success']) {
                return $testResult;
            }

            return [
                'success' => true,
                'config' => [
                    'api_key' => $credentials['api_key'],
                    'base_id' => $credentials['base_id'],
                    'table_name' => $credentials['table_name'],
                    'view_name' => $credentials['view_name'] ?? null,
                    'field_mapping' => $credentials['field_mapping'] ?? $this->getDefaultFieldMapping(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Airtable connection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to connect to Airtable: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Test connection to Airtable.
     */
    public function testConnection(BankFeed $bankFeed): array
    {
        try {
            $config = $bankFeed->connection_config;
            return $this->testAirtableConnection($config);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get transactions from Airtable.
     */
    public function getTransactions(BankFeed $bankFeed, Carbon $startDate, Carbon $endDate): array
    {
        try {
            $config = $bankFeed->connection_config;
            
            if (!isset($config['api_key'], $config['base_id'], $config['table_name'])) {
                throw new \Exception('Missing required Airtable configuration');
            }

            $url = "/{$config['base_id']}/{$config['table_name']}";
            
            // Build query parameters
            $params = [
                'filterByFormula' => $this->buildDateFilter($config, $startDate, $endDate),
                'sort[0][field]' => $config['field_mapping']['date'] ?? 'Date',
                'sort[0][direction]' => 'desc',
            ];

            if (isset($config['view_name'])) {
                $params['view'] = $config['view_name'];
            }

            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['api_key'],
                ],
                'query' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['records'])) {
                throw new \Exception('Invalid response format from Airtable');
            }

            // Normalize transactions
            $normalizedTransactions = [];
            foreach ($data['records'] as $record) {
                $normalizedTransactions[] = $this->normalizeTransaction($record, $bankFeed);
            }

            return $normalizedTransactions;
        } catch (\Exception $e) {
            Log::error('Failed to get Airtable transactions', [
                'bank_feed_id' => $bankFeed->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Update balance from Airtable (not typically supported).
     */
    public function updateBalance(BankFeed $bankFeed): array
    {
        // Airtable doesn't typically provide real-time balance information
        // You might implement this if you have a separate balance tracking table
        return [
            'current_balance_cents' => null,
            'available_balance_cents' => null,
            'balance_updated_at' => null,
        ];
    }

    /**
     * Disconnect from Airtable.
     */
    public function disconnect(BankFeed $bankFeed): bool
    {
        // No special disconnection needed for Airtable
        return true;
    }

    /**
     * Get display name.
     */
    public function getDisplayName(): string
    {
        return 'Airtable';
    }

    /**
     * Get configuration fields for Airtable.
     */
    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'password',
                'required' => true,
                'description' => 'Your Airtable API key',
            ],
            [
                'name' => 'base_id',
                'label' => 'Base ID',
                'type' => 'text',
                'required' => true,
                'description' => 'The ID of your Airtable base (starts with app...)',
            ],
            [
                'name' => 'table_name',
                'label' => 'Table Name',
                'type' => 'text',
                'required' => true,
                'description' => 'The name of the table containing transactions',
            ],
            [
                'name' => 'view_name',
                'label' => 'View Name',
                'type' => 'text',
                'required' => false,
                'description' => 'Optional: specific view to use',
            ],
        ];
    }

    /**
     * Validate Airtable credentials.
     */
    public function validateCredentials(array $credentials): array
    {
        $errors = [];

        if (!isset($credentials['api_key']) || empty($credentials['api_key'])) {
            $errors['api_key'] = 'API key is required';
        }

        if (!isset($credentials['base_id']) || empty($credentials['base_id'])) {
            $errors['base_id'] = 'Base ID is required';
        } elseif (!str_starts_with($credentials['base_id'], 'app')) {
            $errors['base_id'] = 'Base ID should start with "app"';
        }

        if (!isset($credentials['table_name']) || empty($credentials['table_name'])) {
            $errors['table_name'] = 'Table name is required';
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        return [
            'success' => true,
        ];
    }

    /**
     * Normalize Airtable transaction data.
     */
    public function normalizeTransaction(array $sourceTransaction, BankFeed $bankFeed): array
    {
        $config = $bankFeed->connection_config;
        $fieldMapping = $config['field_mapping'] ?? $this->getDefaultFieldMapping();
        $fields = $sourceTransaction['fields'] ?? [];

        // Extract data using field mapping
        $amount = $this->extractFieldValue($fields, $fieldMapping['amount']);
        $date = $this->extractFieldValue($fields, $fieldMapping['date']);
        $description = $this->extractFieldValue($fields, $fieldMapping['description']);
        $category = $this->extractFieldValue($fields, $fieldMapping['category']);

        return [
            'source_transaction_id' => $sourceTransaction['id'],
            'raw_data' => $sourceTransaction,
            'amount' => (float) $amount,
            'date' => $this->parseDate($date),
            'datetime' => $this->parseDateTime($date),
            'description' => (string) $description,
            'category' => $category,
            'merchant_name' => null,
            'status' => 'cleared', // Airtable transactions are typically already cleared
            'pending' => false,
            'pending_transaction_id' => null,
            'currency_code' => 'USD', // Default, could be configurable
            'metadata' => [
                'airtable_record_id' => $sourceTransaction['id'],
                'created_time' => $sourceTransaction['createdTime'] ?? null,
            ],
        ];
    }

    /**
     * Test Airtable connection with provided config.
     */
    protected function testAirtableConnection(array $config): array
    {
        try {
            $url = "/{$config['base_id']}/{$config['table_name']}";
            
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['api_key'],
                ],
                'query' => [
                    'maxRecords' => 1, // Just test with 1 record
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return [
                'success' => true,
                'message' => 'Connection test successful',
                'records_found' => count($data['records'] ?? []),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get default field mapping for Airtable.
     */
    protected function getDefaultFieldMapping(): array
    {
        return [
            'date' => 'Date',
            'amount' => 'Amount',
            'description' => 'Description',
            'category' => 'Category',
        ];
    }

    /**
     * Build date filter for Airtable query.
     */
    protected function buildDateFilter(array $config, Carbon $startDate, Carbon $endDate): string
    {
        $dateField = $config['field_mapping']['date'] ?? 'Date';
        
        return "AND(" .
            "IS_AFTER({{$dateField}}, '" . $startDate->format('Y-m-d') . "'), " .
            "IS_BEFORE({{$dateField}}, '" . $endDate->addDay()->format('Y-m-d') . "')" .
            ")";
    }

    /**
     * Extract field value using field mapping.
     */
    protected function extractFieldValue(array $fields, string $fieldName): mixed
    {
        return $fields[$fieldName] ?? null;
    }

    /**
     * Parse date from Airtable format.
     */
    protected function parseDate(mixed $dateValue): ?string
    {
        if (!$dateValue) {
            return null;
        }

        try {
            return Carbon::parse($dateValue)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('Failed to parse Airtable date', [
                'date_value' => $dateValue,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse datetime from Airtable format.
     */
    protected function parseDateTime(mixed $dateValue): ?string
    {
        if (!$dateValue) {
            return null;
        }

        try {
            return Carbon::parse($dateValue)->toDateTimeString();
        } catch (\Exception $e) {
            return null;
        }
    }
}
