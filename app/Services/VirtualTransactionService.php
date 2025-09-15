<?php

namespace App\Services;

use App\Models\Budget;
use App\Services\AirtableService;
use App\Services\VirtualAccountService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VirtualTransactionService
{
    public function __construct(
        protected AirtableService $airtableService,
        protected VirtualAccountService $virtualAccountService
    ) {}

    /**
     * Get transactions for a budget with 12-hour caching
     */
    public function getTransactionsForBudget(Budget $budget): Collection
    {
        if (!$this->airtableService->isConfigured()) {
            return collect([]);
        }

        // Cache for 12 hours (43200 seconds)
        return Cache::remember("budget_{$budget->id}_airtable_transactions", 43200, function () use ($budget) {
            try {
                $airtableTransactions = $this->airtableService->getAllRecords('Transactions');
                
                Log::info('Fetched transactions from Airtable', [
                    'budget_id' => $budget->id,
                    'transaction_count' => $airtableTransactions->count(),
                    'cached_until' => now()->addHours(12)->toDateTimeString()
                ]);
                
                return $airtableTransactions->map(function ($record) use ($budget) {
                    return $this->transformAirtableTransaction($record, $budget);
                })->filter(); // Remove any null results
            } catch (\Exception $e) {
                Log::error('Failed to fetch transactions from Airtable', [
                    'budget_id' => $budget->id,
                    'error' => $e->getMessage()
                ]);
                return collect([]);
            }
        });
    }

    /**
     * Get historical transactions for a specific virtual account (from Airtable only)
     */
    public function getHistoricalTransactionsForAccount(Budget $budget, string $airtableAccountId): Collection
    {
        $allTransactions = $this->getTransactionsForBudget($budget);
        
        return $allTransactions->filter(function ($transaction) use ($airtableAccountId) {
            $accountField = $transaction['airtable_account_field'] ?? null;
            
            if (is_array($accountField)) {
                return in_array($airtableAccountId, $accountField);
            }
            
            return $accountField === $airtableAccountId;
        })->filter(function ($transaction) {
            // Only return historical transactions (past and today)
            $transactionDate = \Carbon\Carbon::parse($transaction['date']);
            return $transactionDate->lte(now());
        });
    }

    /**
     * Get transactions for a specific virtual account (legacy method for compatibility)
     */
    public function getTransactionsForAccount(Budget $budget, string $airtableAccountId): Collection
    {
        return $this->getHistoricalTransactionsForAccount($budget, $airtableAccountId);
    }

    /**
     * Get combined historical (Airtable) and projected (local) transactions for an account
     */
    public function getCombinedTransactionsForAccount(
        Budget $budget, 
        string $airtableAccountId, 
        \Carbon\Carbon $startDate,
        \Carbon\Carbon $endDate,
        int $monthsToProject = 1
    ): Collection {
        // 1. Get historical transactions from Airtable
        $historicalTransactions = $this->getHistoricalTransactionsForAccount($budget, $airtableAccountId)
            ->filter(function ($transaction) use ($startDate, $endDate) {
                $transactionDate = \Carbon\Carbon::parse($transaction['date']);
                return $transactionDate->between($startDate, $endDate);
            });

        // 2. Get projected transactions from local database (recurring and manual future transactions)
        $projectedTransactions = $this->getProjectedTransactionsForAccount(
            $budget, 
            $airtableAccountId, 
            $endDate,
            $monthsToProject
        );

        // 3. Combine and sort by date
        return $historicalTransactions->concat($projectedTransactions)
            ->sortBy('date')
            ->values();
    }

    /**
     * Get projected transactions for an account from local database
     */
    protected function getProjectedTransactionsForAccount(
        Budget $budget, 
        string $airtableAccountId,
        \Carbon\Carbon $endDate,
        int $monthsToProject
    ): Collection {
        // Get local transactions that reference this Airtable account and are in the future
        $localFutureTransactions = $budget->transactions()
            ->where('airtable_account_id', $airtableAccountId)
            ->where('date', '>', now())
            ->where('date', '<=', $endDate)
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'airtable_id' => null,
                    'airtable_account_id' => $transaction->airtable_account_id,
                    'budget_id' => $transaction->budget_id,
                    'description' => $transaction->description,
                    'category' => $transaction->category ?? 'Uncategorized',
                    'amount_in_cents' => $transaction->amount_in_cents,
                    'amount' => $transaction->amount_in_cents / 100,
                    'date' => $transaction->date->toDateString(),
                    'is_airtable_imported' => false,
                    'is_projected' => true,
                    'is_recurring' => !is_null($transaction->recurring_transaction_template_id),
                    'pending' => false,
                    'created_at' => $transaction->created_at->toISOString(),
                ];
            });

        // TODO: Add recurring transaction support for virtual accounts
        // For now, we only support manual future transactions for virtual accounts
        // We would need to create hybrid Account records to support recurring transactions
        
        \Log::info('Skipping recurring transactions for virtual account', [
            'airtable_account_id' => $airtableAccountId,
            'local_future_transactions' => $localFutureTransactions->count()
        ]);

        return $localFutureTransactions;
    }

    /**
     * Get paginated transactions for frontend display
     */
    public function getPaginatedTransactions(
        Budget $budget, 
        string $airtableAccountId, 
        array $filters = [],
        int $perPage = 15
    ): array {
        $transactions = $this->getTransactionsForAccount($budget, $airtableAccountId);
        
        // Apply filters
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $transactions = $transactions->filter(function ($transaction) use ($search) {
                return str_contains(strtolower($transaction['description']), $search) ||
                       str_contains(strtolower($transaction['category']), $search);
            });
        }

        if (!empty($filters['type'])) {
            $type = $filters['type'];
            $transactions = $transactions->filter(function ($transaction) use ($type) {
                if ($type === 'income') {
                    return $transaction['amount_in_cents'] > 0;
                } elseif ($type === 'expense') {
                    return $transaction['amount_in_cents'] < 0;
                }
                return true;
            });
        }

        if (!empty($filters['category'])) {
            $category = $filters['category'];
            $transactions = $transactions->filter(function ($transaction) use ($category) {
                return str_contains(strtolower($transaction['category']), strtolower($category));
            });
        }

        // Apply date range filter
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $startDate = Carbon::parse($filters['start_date']);
            $endDate = Carbon::parse($filters['end_date']);
            
            $transactions = $transactions->filter(function ($transaction) use ($startDate, $endDate) {
                $transactionDate = Carbon::parse($transaction['date']);
                return $transactionDate->between($startDate, $endDate);
            });
        }

        // Sort by date (newest first)
        $transactions = $transactions->sortByDesc('date')->values();

        // Paginate
        $total = $transactions->count();
        $currentPage = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($currentPage - 1) * $perPage;
        $paginatedData = $transactions->slice($offset, $perPage)->values();

        return [
            'data' => $paginatedData->toArray(),
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $total),
            'prev_page_url' => $currentPage > 1 ? "?page=" . ($currentPage - 1) : null,
            'next_page_url' => $currentPage < ceil($total / $perPage) ? "?page=" . ($currentPage + 1) : null,
        ];
    }

    /**
     * Transform Airtable transaction record into standardized format
     */
    protected function transformAirtableTransaction(array $record, Budget $budget): ?array
    {
        $fields = $record['fields'] ?? [];
        
        // Skip if required fields are missing
        if (empty($fields['*Name']) || empty($fields['**Account'])) {
            return null;
        }

        // Get account information
        $accountField = $fields['**Account'];
        $airtableAccountId = is_array($accountField) ? $accountField[0] : $accountField;
        
        return [
            'id' => $this->generateLocalId($record['id']),
            'airtable_id' => $record['id'],
            'airtable_account_field' => $accountField,
            'airtable_account_id' => $airtableAccountId,
            'budget_id' => $budget->id,
            'description' => $fields['*Name'] ?? 'Unknown Transaction',
            'category' => $fields['*Notes'] ?? 'Uncategorized',
            'amount_in_cents' => round(($fields['**USD'] ?? 0) * 100),
            'amount' => $fields['**USD'] ?? 0,
            'date' => $fields['**Date'] ?? now()->toDateString(),
            'computed_account_name' => $this->getAccountNameFromAirtable($airtableAccountId),
            'is_airtable_imported' => true,
            'is_projected' => false,
            'is_recurring' => false,
            'pending' => false,
            'airtable_metadata' => $record,
            'created_at' => $record['createdTime'] ?? now()->toISOString(),
        ];
    }

    /**
     * Generate consistent local ID from Airtable ID
     */
    protected function generateLocalId(string $airtableId): int
    {
        return abs(crc32($airtableId));
    }

    /**
     * Get account name from Airtable for caching
     */
    protected function getAccountNameFromAirtable(string $airtableAccountId): ?string
    {
        try {
            $accountRecord = $this->airtableService->getRecord('Accounts', $airtableAccountId);
            $fields = $accountRecord['fields'] ?? [];
            return $fields['*Name'] ?? null;
        } catch (\Exception $e) {
            Log::warning('Could not fetch account name from Airtable', [
                'airtable_account_id' => $airtableAccountId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Clear transaction cache for a budget (useful when forcing refresh)
     */
    public function clearCache(Budget $budget): void
    {
        Cache::forget("budget_{$budget->id}_airtable_transactions");
        Log::info('Cleared Airtable transaction cache', ['budget_id' => $budget->id]);
    }

    /**
     * Get cache status information
     */
    public function getCacheStatus(Budget $budget): array
    {
        $cacheKey = "budget_{$budget->id}_airtable_transactions";
        $hasCache = Cache::has($cacheKey);
        
        return [
            'has_cache' => $hasCache,
            'cache_key' => $cacheKey,
            'cache_duration_hours' => 12,
            'next_refresh' => $hasCache ? 'Within 12 hours' : 'On next request'
        ];
    }
}
