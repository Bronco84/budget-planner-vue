<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BudgetService
{
    public function __construct(
        protected RecurringTransactionService $recurringService
    ) {}

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
            now()->addDay(),
            now()->addMonths($monthsToProject)->endOfMonth(),
            $account->budget_id
        );

        // Merge and sort all transactions
        return $actualTransactions->concat($projectedTransactions)->sortBy('date')->values();
    }

    /**
     * Get projected transactions for an account
     */
    protected function getProjectedTransactions(
        Account $account,
        Carbon $startDate,
        Carbon $endDate,
        int $budgetId
    ): Collection {
        // Get recurring transaction projections
        $recurringProjections = collect($this->recurringService->projectTransactions($account, $startDate, $endDate))
            ->map(function ($transaction) use ($budgetId) {
                $model = new Transaction($transaction);
                $model->account = $transaction['account'] ?? null;
                $model->budget_id = $budgetId;
                $model->is_projected = true;
                $model->is_recurring = true;
                $model->date = Carbon::parse($transaction['date']);
                $model->account_id = $transaction['account_id'] ?? null;
                $model->recurring_transaction_template_id = $transaction['recurring_transaction_template_id'] ?? null;
                $model->is_dynamic_amount = $transaction['is_dynamic_amount'] ?? false;
                $model->amount_in_cents = $transaction['amount_in_cents'] ?? 0;
                return $model;
            });

        // Get autopay projections for the entire budget
        $budget = $account->budget;
        $autopayProjections = collect($this->recurringService->generateAutopayProjections($budget, $startDate, $endDate))
            // Filter to only include projections for this specific account
            ->where('account_id', $account->id)
            ->map(function ($transaction) use ($budgetId) {
                $model = new Transaction((array) $transaction);
                $model->account = $transaction->account ?? null;
                $model->budget_id = $budgetId;
                $model->is_projected = true;
                $model->is_recurring = false; // Autopay is not a recurring transaction
                $model->date = $transaction->date instanceof Carbon ? $transaction->date : Carbon::parse($transaction->date);
                $model->account_id = $transaction->account_id;
                $model->category_id = $transaction->category_id ?? null;
                $model->description = $transaction->description;
                $model->amount_in_cents = $transaction->amount_in_cents;
                $model->projection_source = 'autopay';
                return $model;
            });

        // Merge both types of projections
        return $recurringProjections->concat($autopayProjections);
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
        // Sum all account balances. Assets are positive, liabilities are negative in DB.
        // Excluded accounts are ignored.
        return $budget->accounts
            ->filter(fn($account) => !$account->exclude_from_total_balance)
            ->sum('current_balance_cents');
    }

    /**
     * Calculate projected monthly cash flow for an account
     */
    public function calculateMonthlyProjectedCashFlow(Account $account): int
    {
        return $this->recurringService->calculateMonthlyProjectedCashFlow($account);
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

