<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Expense;

class Budget extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'starting_balance_account_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'total_amount',
        'remaining_amount',
        'percent_used',
        'start_date',
        'end_date',
    ];

    /**
     * Get the user that owns the budget.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get users connected to this budget.
     */
    public function connected_users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Get the categories for the budget.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Get the transactions for the budget.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Transaction, \App\Models\Budget>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the accounts for this budget.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Get the starting balance account for this budget.
     */
    public function startingBalanceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'starting_balance_account_id');
    }

    /**
     * Get the recurring transaction templates for the budget.
     */
    public function recurringTransactionTemplates(): HasMany
    {
        return $this->hasMany(RecurringTransactionTemplate::class);
    }

    /**
     * Get monthly statistics for the budget.
     * 
     * @param int|null $month
     * @param int|null $year
     * @return array
     */
    public function getMonthlyStatistics($month = null, $year = null)
    {
        $month = $month ?: now()->month;
        $year = $year ?: now()->year;

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = Carbon::create($year, $month, 1)->endOfMonth();

        // Get all transactions for the current month
        /** @var Collection<int, Transaction> $matchingTransactions */
        $matchingTransactions = $this->transactions()
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get();

        // Calculate totals
        $totalIncome = $matchingTransactions->where('amount_in_cents', '>', 0)->sum('amount_in_cents');
        $totalExpenses = $matchingTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents');

        // Group by category
        $byCategory = $matchingTransactions
            ->groupBy(function (Transaction $transaction) {
                return $transaction->category;
            })
            ->map(function ($transactions) {
                $first = $transactions->first();
                return (object)[
                    'category' => $first instanceof Transaction ? $first->category : null,
                    'total' => $transactions->sum('amount_in_cents')
                ];
            })
            ->values();

        // Calculate previous month's statistics
        $prevMonth = $start->copy()->subMonth();
        /** @var Collection<int, Transaction> $prevMonthTransactions */
        $prevMonthTransactions = $this->transactions()
            ->whereDate('date', '>=', $prevMonth->startOfMonth())
            ->whereDate('date', '<=', $prevMonth->endOfMonth())
            ->get();

        $prevMonthTotalIncome = $prevMonthTransactions->where('amount_in_cents', '>', 0)->sum('amount_in_cents');
        $prevMonthTotalExpenses = $prevMonthTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents');

        // Group previous month by category
        $prevMonthByCategory = $prevMonthTransactions
            ->groupBy(function (Transaction $transaction) {
                return $transaction->category;
            })
            ->map(function ($transactions) {
                $first = $transactions->first();
                return (object)[
                    'category' => $first instanceof Transaction ? $first->category : null,
                    'total' => $transactions->sum('amount_in_cents')
                ];
            })
            ->values();

        // Calculate changes
        $incomeChange = $prevMonthTotalIncome != 0
            ? (($totalIncome - $prevMonthTotalIncome) / abs($prevMonthTotalIncome)) * 100
            : 0;
        $expensesChange = $prevMonthTotalExpenses != 0
            ? ((abs($totalExpenses) - abs($prevMonthTotalExpenses)) / abs($prevMonthTotalExpenses)) * 100
            : 0;

        return [
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'income_change' => $incomeChange,
            'expenses_change' => $expensesChange,
            'by_category' => $byCategory,
            'prev_month_by_category' => $prevMonthByCategory,
            'month_name' => $start->format('F'),
            'year' => $year,
            'prev_month_name' => $prevMonth->format('F')
        ];
    }

    /**
     * Get yearly statistics for the budget.
     * 
     * @param int|null $year
     * @return array
     */
    public function getYearlyStatistics($year = null)
    {
        $year = $year ?: now()->year;
        $monthlyStats = [];

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1)->startOfMonth()->format('Y-m-d');
            $end = Carbon::create($year, $month, 1)->endOfMonth()->format('Y-m-d');

            // Get previous year's data for comparison
            $prevStart = Carbon::create($year - 1, $month, 1)->startOfMonth()->format('Y-m-d');
            $prevEnd = Carbon::create($year - 1, $month, 1)->endOfMonth()->format('Y-m-d');

            $currentMonthStats = [
                'total_income' => $this->transactions()
                    ->where('amount_in_cents', '>', 0)
                    ->whereDate('date', '>=', $start)
                    ->whereDate('date', '<=', $end)
                    ->sum('amount_in_cents'),
                'total_expenses' => $this->transactions()
                    ->where('amount_in_cents', '<', 0)
                    ->whereDate('date', '>=', $start)
                    ->whereDate('date', '<=', $end)
                    ->sum('amount_in_cents'),
                'prev_year_income' => $this->transactions()
                    ->where('amount_in_cents', '>', 0)
                    ->whereDate('date', '>=', $prevStart)
                    ->whereDate('date', '<=', $prevEnd)
                    ->sum('amount_in_cents'),
                'prev_year_expenses' => $this->transactions()
                    ->where('amount_in_cents', '<', 0)
                    ->whereDate('date', '>=', $prevStart)
                    ->whereDate('date', '<=', $prevEnd)
                    ->sum('amount_in_cents'),
                'month_number' => $month,
                'year' => $year
            ];

            // Calculate year-over-year changes
            $currentMonthStats['income_change'] = $currentMonthStats['prev_year_income'] != 0
                ? (($currentMonthStats['total_income'] - $currentMonthStats['prev_year_income']) / abs($currentMonthStats['prev_year_income'])) * 100
                : null;
            $currentMonthStats['expenses_change'] = $currentMonthStats['prev_year_expenses'] != 0
                ? (($currentMonthStats['total_expenses'] - $currentMonthStats['prev_year_expenses']) / abs($currentMonthStats['prev_year_expenses'])) * 100
                : null;

            $monthlyStats[Carbon::create($year, $month, 1)->format('F')] = $currentMonthStats;
        }

        $yearlyTotals = [
            'income' => array_sum(array_column($monthlyStats, 'total_income')),
            'expenses' => array_sum(array_column($monthlyStats, 'total_expenses')),
            'prev_year_income' => array_sum(array_column($monthlyStats, 'prev_year_income')),
            'prev_year_expenses' => array_sum(array_column($monthlyStats, 'prev_year_expenses'))
        ];

        // Calculate yearly changes
        $yearlyTotals['income_change'] = $yearlyTotals['prev_year_income'] != 0
            ? (($yearlyTotals['income'] - $yearlyTotals['prev_year_income']) / abs($yearlyTotals['prev_year_income'])) * 100
            : null;
        $yearlyTotals['expenses_change'] = $yearlyTotals['prev_year_expenses'] != 0
            ? (($yearlyTotals['expenses'] - $yearlyTotals['prev_year_expenses']) / abs($yearlyTotals['prev_year_expenses'])) * 100
            : null;

        return [
            'monthly' => $monthlyStats,
            'yearly_totals' => $yearlyTotals
        ];
    }

    /**
     * Get the total budget amount based on category allocations.
     *
     * @return float
     */
    public function getTotalAmountAttribute(): float
    {
        // Use the actual column name 'amount' instead of the accessor 'allocated_amount'
        return $this->categories()->sum('amount');
    }

    /**
     * Get the remaining budget amount.
     *
     * @return float
     */
    public function getRemainingAmountAttribute(): float
    {
        // Calculate based on actual columns
        $totalAllocated = $this->categories()->sum('amount');
        
        // For expenses, we need to calculate the sum manually from the relationships
        $totalSpent = 0;
        foreach ($this->categories as $category) {
            $totalSpent += $category->expenses()->sum('amount');
        }
        
        return $totalAllocated - $totalSpent;
    }

    /**
     * Get the percentage of budget used.
     *
     * @return float
     */
    public function getPercentUsedAttribute(): float
    {
        $totalAllocated = $this->categories()->sum('amount');
        
        if ($totalAllocated <= 0) {
            return 0;
        }
        
        // For expenses, we need to calculate the sum manually from the relationships
        $totalSpent = 0;
        foreach ($this->categories as $category) {
            $totalSpent += $category->expenses()->sum('amount');
        }
        
        $percentUsed = ($totalSpent / $totalAllocated) * 100;
        
        return min($percentUsed, 100);
    }

    /**
     * Get the budget start date (current month start).
     *
     * @return string
     */
    public function getStartDateAttribute(): string
    {
        return now()->startOfMonth()->format('Y-m-d');
    }

    /**
     * Get the budget end date (current month end).
     *
     * @return string
     */
    public function getEndDateAttribute(): string
    {
        return now()->endOfMonth()->format('Y-m-d');
    }

    /**
     * Get the expenses for this budget through categories.
     */
    public function expenses()
    {
        return $this->hasManyThrough(Expense::class, Category::class);
    }
}
