<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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
        'total_amount',
        'start_date',
        'end_date',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_amount' => 'decimal:2',
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
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the remaining amount for the budget.
     */
    public function getRemainingAmountAttribute()
    {
        $spent = $this->transactions()->sum('amount');
        return $this->total_amount - $spent;
    }

    /**
     * Get the percentage used of the budget.
     */
    public function getPercentUsedAttribute()
    {
        if ($this->total_amount > 0) {
            $spent = $this->transactions()->sum('amount');
            return ($spent / $this->total_amount) * 100;
        }
        return 0;
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
        $matchingTransactions = $this->transactions()
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get();

        // Calculate totals
        $totalIncome = $matchingTransactions->where('amount_in_cents', '>', 0)->sum('amount_in_cents');
        $totalExpenses = $matchingTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents');

        // Group by category
        $byCategory = $matchingTransactions
            ->groupBy(fn ($transaction) => $transaction->category)
            ->map(function($transactions) {
                return (object)[
                    'category' => $transactions->first()->category,
                    'total' => $transactions->sum('amount_in_cents')
                ];
            })
            ->values();

        // Calculate previous month's statistics
        $prevMonth = $start->copy()->subMonth();
        $prevMonthTransactions = $this->transactions()
            ->whereDate('date', '>=', $prevMonth->startOfMonth())
            ->whereDate('date', '<=', $prevMonth->endOfMonth())
            ->get();

        $prevMonthTotalIncome = $prevMonthTransactions->where('amount_in_cents', '>', 0)->sum('amount_in_cents');
        $prevMonthTotalExpenses = $prevMonthTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents');

        // Group previous month by category
        $prevMonthByCategory = $prevMonthTransactions
            ->groupBy(fn ($transaction) => $transaction->category)
            ->map(function($transactions) {
                return (object)[
                    'category' => $transactions->first()->category,
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
}
