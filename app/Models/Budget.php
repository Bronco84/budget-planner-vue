<?php

namespace App\Models;

use App\Enums\BudgetRole;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'color',
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
     * The relationships that should be eager loaded.
     *
     * @var array
     */
    protected $with = ['accounts'];

    /**
     * Get the user that owns the budget.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get users connected to this budget (shared members, excluding the primary owner).
     */
    public function connected_users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('access_level')->withTimestamps();
    }

    /**
     * Alias of {@see connected_users()} whose name matches the `{member}` route
     * parameter, so scoped route bindings resolve a member through the budget.
     */
    public function members(): BelongsToMany
    {
        return $this->connected_users();
    }

    /**
     * Invitations to join this budget.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(BudgetInvitation::class);
    }

    /**
     * The user's role on this budget: owner for the primary owner, the pivot
     * access level for a shared member, or null when the user has no access.
     * This is the single source of truth for budget authorization.
     */
    public function roleFor(?User $user): ?BudgetRole
    {
        if (! $user) {
            return null;
        }

        if ((int) $this->user_id === (int) $user->id) {
            return BudgetRole::Owner;
        }

        $level = $this->connected_users()
            ->where('users.id', $user->id)
            ->value('budget_user.access_level');

        return $level === null ? null : BudgetRole::fromStored($level);
    }

    /**
     * Whether the user is the primary owner (the budget's creator, or whoever
     * primary ownership was transferred to).
     */
    public function isPrimaryOwner(?User $user): bool
    {
        return $user !== null && (int) $this->user_id === (int) $user->id;
    }

    /**
     * Budgets the user owns, either as primary owner or as an owner member.
     *
     * @param  Builder<Budget>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->where('budgets.user_id', $user->id)
                ->orWhereHas('connected_users', function (Builder $query) use ($user) {
                    $query->where('users.id', $user->id)
                        ->whereIn('budget_user.access_level', ['owner', 'admin']);
                });
        });
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
     * @return HasMany<Transaction, Budget>
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
     * Alias of {@see recurringTransactionTemplates()} whose name matches the
     * `{recurring_transaction}` route parameter, so scoped route bindings can
     * resolve the child through the parent budget.
     */
    public function recurringTransactions(): HasMany
    {
        return $this->recurringTransactionTemplates();
    }

    /**
     * Get the transfers for the budget. Enables scoped route binding for the
     * `{transfer}` route parameter.
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    /**
     * Get the payoff plans for the budget.
     */
    public function payoffPlans(): HasMany
    {
        return $this->hasMany(PayoffPlan::class);
    }

    /**
     * Get the properties for this budget.
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * Get the scenarios for this budget.
     */
    public function scenarios(): HasMany
    {
        return $this->hasMany(Scenario::class);
    }

    /**
     * Get all file attachments for this budget.
     */
    public function fileAttachments(): MorphMany
    {
        return $this->morphMany(FileAttachment::class, 'attachable');
    }

    /**
     * Get all files for this budget through file attachments.
     */
    public function files()
    {
        return $this->hasManyThrough(
            File::class,
            FileAttachment::class,
            'attachable_id', // Foreign key on file_attachments table
            'id', // Foreign key on files table
            'id', // Local key on budgets table
            'file_id' // Local key on file_attachments table
        )->where('file_attachments.attachable_type', Budget::class);
    }

    /**
     * Get monthly statistics for the budget.
     *
     * @param  int|null  $month
     * @param  int|null  $year
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
            ->with('budgetCategory')
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get();

        // Calculate totals
        $totalIncome = $matchingTransactions->where('amount_in_cents', '>', 0)->sum('amount_in_cents');
        $totalExpenses = $matchingTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents');

        // Group by category
        $byCategory = $matchingTransactions
            ->groupBy(function (Transaction $transaction) {
                return $transaction->category_name;
            })
            ->map(function ($transactions) {
                $first = $transactions->first();

                return (object) [
                    'category' => $first instanceof Transaction ? $first->category_name : null,
                    'total' => $transactions->sum('amount_in_cents'),
                ];
            })
            ->values();

        // Calculate previous month's statistics
        $prevMonth = $start->copy()->subMonth();
        /** @var Collection<int, Transaction> $prevMonthTransactions */
        $prevMonthTransactions = $this->transactions()
            ->with('budgetCategory')
            ->whereDate('date', '>=', $prevMonth->startOfMonth())
            ->whereDate('date', '<=', $prevMonth->endOfMonth())
            ->get();

        $prevMonthTotalIncome = $prevMonthTransactions->where('amount_in_cents', '>', 0)->sum('amount_in_cents');
        $prevMonthTotalExpenses = $prevMonthTransactions->where('amount_in_cents', '<', 0)->sum('amount_in_cents');

        // Group previous month by category
        $prevMonthByCategory = $prevMonthTransactions
            ->groupBy(function (Transaction $transaction) {
                return $transaction->category_name;
            })
            ->map(function ($transactions) {
                $first = $transactions->first();

                return (object) [
                    'category' => $first instanceof Transaction ? $first->category_name : null,
                    'total' => $transactions->sum('amount_in_cents'),
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
            'prev_month_name' => $prevMonth->format('F'),
        ];
    }

    /**
     * Get yearly statistics for the budget.
     *
     * @param  int|null  $year
     * @return array
     */
    public function getYearlyStatistics($year = null)
    {
        $year = $year ?: now()->year;
        $monthlyStats = [];

        $totals = $this->getMonthlyIncomeExpenseTotals($year - 1, $year);

        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $prevKey = sprintf('%04d-%02d', $year - 1, $month);

            $currentMonthStats = [
                'total_income' => $totals[$key]['income'] ?? 0,
                'total_expenses' => $totals[$key]['expenses'] ?? 0,
                'prev_year_income' => $totals[$prevKey]['income'] ?? 0,
                'prev_year_expenses' => $totals[$prevKey]['expenses'] ?? 0,
                'month_number' => $month,
                'year' => $year,
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
            'prev_year_expenses' => array_sum(array_column($monthlyStats, 'prev_year_expenses')),
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
            'yearly_totals' => $yearlyTotals,
        ];
    }

    /**
     * Income (amount > 0) and expense (amount < 0) sums per calendar month for
     * the given years, from one grouped query: ['YYYY-MM' => ['income' => int,
     * 'expenses' => int]]. Months are bucketed by the date part of the stored
     * value, matching the whereDate() month-range filters this replaced.
     */
    private function getMonthlyIncomeExpenseTotals(int $fromYear, int $toYear): array
    {
        $totals = [];

        $this->transactions()
            ->whereDate('date', '>=', Carbon::create($fromYear, 1, 1)->format('Y-m-d'))
            ->whereDate('date', '<=', Carbon::create($toYear, 12, 31)->format('Y-m-d'))
            ->where('amount_in_cents', '!=', 0)
            ->groupBy('date')
            ->selectRaw('date')
            ->selectRaw('SUM(CASE WHEN amount_in_cents > 0 THEN amount_in_cents ELSE 0 END) as income')
            ->selectRaw('SUM(CASE WHEN amount_in_cents < 0 THEN amount_in_cents ELSE 0 END) as expenses')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$totals) {
                $month = substr((string) $row->date, 0, 7);
                $totals[$month]['income'] = ($totals[$month]['income'] ?? 0) + (int) $row->income;
                $totals[$month]['expenses'] = ($totals[$month]['expenses'] ?? 0) + (int) $row->expenses;
            });

        return $totals;
    }

    /**
     * Get the total budget amount based on category allocations.
     */
    public function getTotalAmountAttribute(): float
    {
        // Use the actual column name 'amount' instead of the accessor 'allocated_amount'
        return $this->categories()->sum('amount');
    }

    /**
     * Get the remaining budget amount.
     */
    public function getRemainingAmountAttribute(): float
    {
        // Calculate based on actual columns
        $totalAllocated = $this->categories()->sum('amount');

        // Calculate spent from transactions (negative amounts are expenses)
        $totalSpent = 0;
        foreach ($this->categories as $category) {
            $spent = $this->transactions()
                ->inCategory($category)
                ->where('amount_in_cents', '<', 0)
                ->sum('amount_in_cents');
            $totalSpent += abs($spent);
        }

        return $totalAllocated - ($totalSpent / 100); // Convert cents to dollars
    }

    /**
     * Get the percentage of budget used.
     */
    public function getPercentUsedAttribute(): float
    {
        $totalAllocated = $this->categories()->sum('amount');

        if ($totalAllocated <= 0) {
            return 0;
        }

        // Calculate spent from transactions (negative amounts are expenses)
        $totalSpent = 0;
        foreach ($this->categories as $category) {
            $spent = $this->transactions()
                ->inCategory($category)
                ->where('amount_in_cents', '<', 0)
                ->sum('amount_in_cents');
            $totalSpent += abs($spent);
        }

        $totalSpentDollars = $totalSpent / 100; // Convert cents to dollars
        $percentUsed = ($totalSpentDollars / $totalAllocated) * 100;

        return min($percentUsed, 100);
    }

    /**
     * Get the budget start date (current month start).
     */
    public function getStartDateAttribute(): string
    {
        return now()->startOfMonth()->format('Y-m-d');
    }

    /**
     * Get the budget end date (current month end).
     */
    public function getEndDateAttribute(): string
    {
        return now()->endOfMonth()->format('Y-m-d');
    }
}
