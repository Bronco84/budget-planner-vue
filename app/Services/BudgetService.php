<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BudgetService
{
    /**
     * Cache TTL for projections in seconds (1 hour)
     */
    protected const PROJECTION_CACHE_TTL = 3600;

    /**
     * Cache TTL for monthly cash flow in seconds (1 hour)
     */
    protected const CASH_FLOW_CACHE_TTL = 3600;

    public function __construct(
        protected RecurringTransactionService $recurringService,
        protected ?TransferService $transferService = null
    ) {
        // TransferService is optional for backwards compatibility
        if ($this->transferService === null) {
            $this->transferService = app(TransferService::class);
        }
    }

    /**
     * Get the appropriate account for the budget view
     */
    public function getSelectedAccount(Budget $budget, ?int $requestedAccountId, array $userAccountTypeOrder): ?Account
    {
        if ($budget->accounts->count() === 0) {
            return null;
        }

        // If a specific account was requested, use that
        if ($requestedAccountId) {
            return $budget->accounts()->where('id', $requestedAccountId)->first();
        }

        // Otherwise, get the first account according to user's preferred account type order
        $accounts = $budget->accounts;
        $accountsByType = $accounts->groupBy('type');

        // Find the first account type that has accounts, according to user preference
        foreach ($userAccountTypeOrder as $type) {
            if ($accountsByType->has($type) && $accountsByType[$type]->isNotEmpty()) {
                return $accountsByType[$type]->first();
            }
        }

        // Fallback to just the first account if no match found
        return $accounts->first();
    }

    /**
     * Get all transactions (actual, pending, and projected) for an account
     */
    public function getAccountTransactions(
        Account $account,
        Carbon $startDate,
        Carbon $endDate,
        int $monthsToProject
    ): Collection {
        // Get actual transactions including pending ones
        $query = $account->transactions()
            ->select(
                'transactions.*',
                'plaid_transactions.pending as pending',
            )
            ->selectRaw("transactions.date > ? as is_projected", [now()->toDateString()])
            ->selectRaw('false as is_recurring')
            ->with(['account', 'plaidTransaction'])
            ->leftJoin('plaid_transactions', 'transactions.plaid_transaction_id', '=', 'plaid_transactions.plaid_transaction_id')
            ->where(function($query) use ($startDate, $endDate) {
                $query->where('transactions.date', '>=', $startDate)
                    ->where('transactions.date', '<=', $endDate)
                    ->orWhereHas('plaidTransaction', function($q) {
                        // pending transactions should always be included
                        // since they are technically "future" transactions without a date
                        $q->where('pending', true);
                    });
            })
            // Remove any transaction that doesn't have a plaid ID if trx date is in the past
            // We consider plaid feed as the source of truth for transactions in the past
            ->where(function($query) {
                $query->where(function($q) {
                    $q->where('transactions.date', '>', now())->whereNull('transactions.plaid_transaction_id');
                });
                $query->orWhere(function($q) {
                    $q->where('transactions.date', '<=', now())->whereNotNull('transactions.plaid_transaction_id');
                });
            });

        $actualTransactions = $query->get();

        // Get projected transactions
        $projectedTransactions = $this->getProjectedTransactions(
            $account,
            now()->addDay()->startOfDay(),
            now()->addMonths($monthsToProject)->endOfMonth(),
            $account->budget_id
        );

        // Merge and sort all transactions
        return $actualTransactions->concat($projectedTransactions)->sortBy('date')->values();
    }

    /**
     * Get projected transactions for an account with caching.
     * Cache is invalidated when templates, transactions, or account autopay settings change.
     */
    protected function getProjectedTransactions(
        Account $account,
        Carbon $startDate,
        Carbon $endDate,
        int $budgetId
    ): Collection {
        // Cache key includes account ID and date range for granular cache control
        $cacheKey = "account:{$account->id}:projections:{$startDate->format('Y-m-d')}:{$endDate->format('Y-m-d')}";

        // Get raw projection data from cache or compute
        $cachedData = Cache::remember($cacheKey, self::PROJECTION_CACHE_TTL, function () use ($account, $startDate, $endDate, $budgetId) {
            return $this->computeProjectedTransactions($account, $startDate, $endDate, $budgetId);
        });

        // Convert cached arrays back to Transaction models
        return collect($cachedData)->map(function ($transactionData) {
            $model = new Transaction($transactionData);
            $model->budget_id = $transactionData['budget_id'] ?? null;
            $model->is_projected = $transactionData['is_projected'] ?? true;
            $model->is_recurring = $transactionData['is_recurring'] ?? false;
            $model->is_transfer = $transactionData['is_transfer'] ?? false;
            $model->date = isset($transactionData['date']) ? Carbon::parse($transactionData['date']) : null;
            $model->account_id = $transactionData['account_id'] ?? null;
            $model->recurring_transaction_template_id = $transactionData['recurring_transaction_template_id'] ?? null;
            $model->transfer_id = $transactionData['transfer_id'] ?? null;
            $model->is_dynamic_amount = $transactionData['is_dynamic_amount'] ?? false;
            $model->amount_in_cents = $transactionData['amount_in_cents'] ?? 0;
            $model->category_id = $transactionData['category_id'] ?? null;
            $model->description = $transactionData['description'] ?? null;
            $model->projection_source = $transactionData['projection_source'] ?? null;
            $model->is_first_autopay = $transactionData['is_first_autopay'] ?? null;
            $model->transfer_from_account = $transactionData['transfer_from_account'] ?? null;
            $model->transfer_to_account = $transactionData['transfer_to_account'] ?? null;
            return $model;
        });
    }

    /**
     * Compute projected transactions without caching.
     * Returns array data suitable for caching.
     */
    protected function computeProjectedTransactions(
        Account $account,
        Carbon $startDate,
        Carbon $endDate,
        int $budgetId
    ): array {
        // Get recurring transaction projections
        $recurringProjections = collect($this->recurringService->projectTransactions($account, $startDate, $endDate))
            ->map(function ($transaction) use ($budgetId) {
                return [
                    'budget_id' => $budgetId,
                    'is_projected' => true,
                    'is_recurring' => true,
                    'date' => $transaction['date'] instanceof Carbon ? $transaction['date']->toDateString() : $transaction['date'],
                    'account_id' => $transaction['account_id'] ?? null,
                    'recurring_transaction_template_id' => $transaction['recurring_transaction_template_id'] ?? null,
                    'is_dynamic_amount' => $transaction['is_dynamic_amount'] ?? false,
                    'amount_in_cents' => $transaction['amount_in_cents'] ?? 0,
                    'description' => $transaction['description'] ?? null,
                    'category' => $transaction['category'] ?? null,
                ];
            });

        // Get autopay projections for the entire budget
        // Load budget with categories to avoid N+1 in autopay category lookup
        if (!$account->relationLoaded('budget')) {
            $account->load('budget.categories');
        } elseif (!$account->budget->relationLoaded('categories')) {
            $account->budget->load('categories');
        }
        $budget = $account->budget;
        $autopayProjections = collect($this->recurringService->generateAutopayProjections($budget, $startDate, $endDate))
            // Filter to only include projections for this specific account
            ->where('account_id', $account->id)
            ->map(function ($transaction) use ($budgetId) {
                return [
                    'budget_id' => $budgetId,
                    'is_projected' => true,
                    'is_recurring' => false,
                    'date' => $transaction->date instanceof Carbon ? $transaction->date->toDateString() : $transaction->date,
                    'account_id' => $transaction->account_id,
                    'category_id' => $transaction->category_id ?? null,
                    'description' => $transaction->description,
                    'amount_in_cents' => $transaction->amount_in_cents,
                    'projection_source' => 'autopay',
                    'is_first_autopay' => $transaction->is_first_autopay ?? true,
                ];
            });

        // Get transfer projections for this account
        $transferProjections = $this->transferService->getProjectedTransferTransactions($account, $startDate, $endDate)
            ->map(function ($transaction) use ($budgetId) {
                return [
                    'budget_id' => $budgetId,
                    'is_projected' => true,
                    'is_recurring' => false,
                    'is_transfer' => true,
                    'date' => $transaction['date'] instanceof Carbon ? $transaction['date']->toDateString() : $transaction['date'],
                    'account_id' => $transaction['account_id'],
                    'transfer_id' => $transaction['transfer_id'] ?? null,
                    'description' => $transaction['description'],
                    'category' => $transaction['category'] ?? 'Transfer',
                    'amount_in_cents' => $transaction['amount_in_cents'],
                    'projection_source' => 'transfer',
                    'transfer_from_account' => $transaction['transfer_from_account'] ?? null,
                    'transfer_to_account' => $transaction['transfer_to_account'] ?? null,
                ];
            });

        // Merge all types of projections and return as array
        return $recurringProjections->concat($autopayProjections)->concat($transferProjections)->values()->toArray();
    }

    /**
     * Clear the monthly cash flow cache for an account.
     */
    public static function clearCashFlowCache(int $accountId): void
    {
        Cache::forget("account:{$accountId}:monthly_cash_flow");
    }

    /**
     * Clear all caches for an account.
     */
    public static function clearAccountCaches(int $accountId): void
    {
        self::clearProjectionCache($accountId);
        self::clearCashFlowCache($accountId);
    }

    /**
     * Clear the projection cache for an account.
     * Generates and clears all possible cache keys for common projection date ranges.
     */
    public static function clearProjectionCache(int $accountId): void
    {
        // Generate cache keys for common projection date ranges (1-12 months)
        // This ensures we clear all cached projections regardless of cache driver
        $today = Carbon::now();
        
        for ($months = 1; $months <= 12; $months++) {
            $startDate = $today->copy()->addDay()->startOfDay()->format('Y-m-d');
            $endDate = $today->copy()->addMonths($months)->endOfMonth()->format('Y-m-d');
            $cacheKey = "account:{$accountId}:projections:{$startDate}:{$endDate}";
            Cache::forget($cacheKey);
        }
        
        // Also try Redis pattern matching for Redis/Memcached drivers
        self::forgetCachePattern("account:{$accountId}:projections:*");
    }

    /**
     * Forget cache keys matching a pattern.
     * For Redis/Memcached cache drivers that support pattern-based deletion.
     */
    protected static function forgetCachePattern(string $pattern): void
    {
        $cacheDriver = config('cache.default');
        
        if (in_array($cacheDriver, ['redis', 'memcached'])) {
            // These drivers support pattern-based deletion
            try {
                $store = Cache::getStore();
                if (method_exists($store, 'getRedis')) {
                    $redis = $store->getRedis()->connection();
                    $prefix = config('cache.prefix', 'laravel_cache');
                    $keys = $redis->keys($prefix . ':' . str_replace('*', '*', $pattern));
                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                }
            } catch (\Exception $e) {
                // Silently fail for pattern deletion
            }
        }
        // For file/array/database cache, keys are cleared individually in clearProjectionCache
    }

    /**
     * Calculate running balances for all transactions.
     * 
     * For asset accounts (checking, savings): balance += transaction amount
     * For liability accounts (credit cards, loans): balance -= transaction amount
     * 
     * This is because:
     * - Asset: positive transaction (deposit) increases balance
     * - Liability: negative transaction (purchase) increases debt (balance)
     */
    public function calculateRunningBalances(
        Collection $allTransactions,
        Account $account
    ): Collection {
        $accountId = $account->id;
        $currentBalanceCents = $account->current_balance_cents;
        $isLiability = $account->isLiability();
        
        // Split transactions into actual, pending, and projected for this account
        $accountTransactions = $allTransactions->where('account_id', $accountId);

        // Get actual (non-pending) transactions
        $actualTransactions = $allTransactions
            ->where('is_projected', false)
            ->filter(function($transaction) {
                return !$transaction->pending;
            });

        // Get pending transactions
        $pendingTransactions = $accountTransactions
            ->where('is_projected', false)
            ->filter(function($transaction) {
                return $transaction->pending;
            });

        // Get projected transactions
        $projectedTransactions = $accountTransactions
            ->where('is_projected', true);

        $runningBalance = $currentBalanceCents;

        // Process actual transactions in reverse chronological order (going backwards in time)
        foreach ($actualTransactions->reverse() as $transaction) {
            $transaction->running_balance = $runningBalance;
            
            // To find the PREVIOUS balance (going backwards):
            // Assets: previous = current - transaction (deposit added to get current, so subtract to go back)
            // Liabilities: previous = current + transaction (purchase subtracted to get current, so add to go back)
            if ($isLiability) {
                $runningBalance = $runningBalance + $transaction->amount_in_cents;
            } else {
                $runningBalance = $runningBalance - $transaction->amount_in_cents;
            }
        }

        // Reset balance to current for pending and projected transactions
        $runningBalance = $currentBalanceCents;

        // Process pending transactions in chronological order
        foreach ($pendingTransactions as $transaction) {
            if ($isLiability) {
                // For liabilities: spending (negative tx) increases debt, payments (positive tx) decrease debt
                $runningBalance -= $transaction->amount_in_cents;
            } else {
                // For assets: spending (negative tx) decreases balance, deposits (positive tx) increase balance
                $runningBalance += $transaction->amount_in_cents;
            }
            $transaction->running_balance = $runningBalance;
        }

        // Process projected transactions in chronological order
        foreach ($projectedTransactions as $transaction) {
            if ($isLiability) {
                // For liabilities: spending (negative tx) increases debt, payments (positive tx) decrease debt
                $runningBalance -= $transaction->amount_in_cents;
            } else {
                // For assets: spending (negative tx) decreases balance, deposits (positive tx) increase balance
                $runningBalance += $transaction->amount_in_cents;
            }
            $transaction->running_balance = $runningBalance;
        }

        // Sort all transactions by date descending for display
        return $allTransactions->reverse()->values();
    }

    /**
     * Paginate a collection of transactions
     */
    public function paginateTransactions(
        Collection $transactions,
        int $page = 1,
        int $perPage = 50,
        string $path = '',
        array $query = []
    ): LengthAwarePaginator {
        $offset = ($page - 1) * $perPage;

        return new LengthAwarePaginator(
            $transactions->slice($offset, $perPage),
            $transactions->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => $query]
        );
    }

    /**
     * Calculate the total balance across all accounts in the budget
     */
    public function calculateTotalBalance(Budget $budget): int
    {
        // Calculate net worth: assets minus liabilities
        // Excluded accounts (exclude_from_total_balance = true) are ignored.
        $accountsBalance = $budget->accounts
            ->filter(fn($account) => !$account->exclude_from_total_balance)
            ->sum(function ($account) {
                // Liabilities (credit cards, mortgages, loans, etc.) are subtracted
                // Assets (checking, savings, investments, etc.) are added
                return $account->isLiability()
                    ? -abs($account->current_balance_cents)
                    : $account->current_balance_cents;
            });

        // Add physical properties (real estate, vehicles) to net worth
        // Note: Linked loans are already subtracted in the accounts calculation above
        $propertiesValue = $budget->properties->sum('current_value_cents');

        return $accountsBalance + $propertiesValue;
    }

    /**
     * Calculate projected monthly cash flow for an account with caching.
     */
    public function calculateMonthlyProjectedCashFlow(Account $account): int
    {
        $cacheKey = "account:{$account->id}:monthly_cash_flow";

        return Cache::remember($cacheKey, self::CASH_FLOW_CACHE_TTL, function () use ($account) {
            return $this->recurringService->calculateMonthlyProjectedCashFlow($account);
        });
    }

    /**
     * Parse date range input and return a Carbon start date
     */
    public function parseDateRange(string $dateRange, ?string $customStartDate = null): Carbon
    {
        return match($dateRange) {
            '7' => now()->subDays(7),
            '30' => now()->subDays(30),
            '90' => now()->subDays(90),
            'custom' => $customStartDate ? Carbon::parse($customStartDate) : now()->subDays(90),
            default => now()->startOfYear(),
        };
    }
}

