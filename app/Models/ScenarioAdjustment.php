<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioAdjustment extends Model
{
    use HasFactory;

    /**
     * Adjustment type constants.
     */
    const TYPE_ONE_TIME_EXPENSE = 'one_time_expense';

    const TYPE_RECURRING_EXPENSE = 'recurring_expense';

    const TYPE_DEBT_PAYDOWN = 'debt_paydown';

    const TYPE_SAVINGS_CONTRIBUTION = 'savings_contribution';

    const TYPE_MODIFY_EXISTING = 'modify_existing';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'scenario_id',
        'account_id',
        'adjustment_type',
        'amount_in_cents',
        'start_date',
        'end_date',
        'frequency',
        'day_of_week',
        'day_of_month',
        'first_day_of_month',
        'description',
        'target_recurring_template_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_in_cents' => 'integer',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'first_day_of_month' => 'integer',
    ];

    /**
     * Get the scenario that owns this adjustment.
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /**
     * Get the account that this adjustment affects.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the recurring transaction template (for modify_existing type).
     */
    public function targetRecurringTemplate(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionTemplate::class, 'target_recurring_template_id');
    }

    /**
     * Generate projected transactions for this adjustment within the date range.
     *
     * @return array Array of transaction arrays
     */
    public function generateProjectedTransactions(Carbon $startDate, Carbon $endDate): array
    {
        $transactions = [];

        // Ensure adjustment dates overlap with projection range
        $adjustmentStart = $this->start_date->greaterThan($startDate)
            ? $this->start_date
            : $startDate;

        $adjustmentEnd = $this->end_date && $this->end_date->lessThan($endDate)
            ? $this->end_date
            : $endDate;

        // If adjustment doesn't overlap with projection range, return empty
        if ($adjustmentStart->greaterThan($adjustmentEnd)) {
            return [];
        }

        switch ($this->adjustment_type) {
            case self::TYPE_ONE_TIME_EXPENSE:
                // Single transaction on start_date if within range
                if ($this->start_date->between($startDate, $endDate)) {
                    $transactions[] = [
                        'date' => $this->start_date->format('Y-m-d'),
                        'amount_in_cents' => $this->amount_in_cents,
                        'description' => $this->description ?? 'Scenario adjustment',
                        'is_projected' => true,
                        'is_scenario_adjustment' => true,
                    ];
                }
                break;

            case self::TYPE_RECURRING_EXPENSE:
            case self::TYPE_DEBT_PAYDOWN:
            case self::TYPE_SAVINGS_CONTRIBUTION:
                // Generate recurring transactions
                $transactions = $this->generateRecurringTransactions($adjustmentStart, $adjustmentEnd);
                break;

            case self::TYPE_MODIFY_EXISTING:
                // This type modifies existing transactions rather than creating new ones
                // Handled separately in the projection calculation
                break;
        }

        return $transactions;
    }

    /**
     * Generate recurring transactions based on frequency.
     */
    protected function generateRecurringTransactions(Carbon $startDate, Carbon $endDate): array
    {
        $transactions = [];

        // Day-of-month schedules are computed per calendar month so days 29-31
        // clamp to the month's last day instead of overflowing into the next month.
        if ($this->frequency === 'monthly') {
            return $this->generateDayOfMonthTransactions($startDate, $endDate, [
                $this->day_of_month ?? $this->start_date->day,
            ]);
        }

        if ($this->frequency === 'bimonthly') {
            // Same convention as recurring templates: first day in first_day_of_month,
            // second day in day_of_month (defaults 1st and 15th).
            return $this->generateDayOfMonthTransactions($startDate, $endDate, [
                $this->first_day_of_month ?? 1,
                $this->day_of_month ?? 15,
            ]);
        }

        $currentDate = $startDate->copy();
        $iterations = 0;

        while ($currentDate->lessThanOrEqualTo($endDate)) {
            $iterations++;

            if ($this->shouldGenerateForDate($currentDate)) {
                $transactions[] = $this->makeProjectedTransaction($currentDate);
            }

            $currentDate = $this->getNextDate($currentDate);

            // Safety check to prevent infinite loops
            if ($currentDate->greaterThan($endDate->copy()->addYear())) {
                break;
            }

            if ($iterations > 1000) {
                break;
            }
        }

        return $transactions;
    }

    /**
     * Generate transactions on the given days of every month in the range,
     * clamping each day to the month's length.
     *
     * @param  array<int>  $days
     */
    protected function generateDayOfMonthTransactions(Carbon $startDate, Carbon $endDate, array $days): array
    {
        $days = array_unique($days);
        sort($days);

        $transactions = [];
        $month = $startDate->copy()->startOfMonth();

        while ($month->lessThanOrEqualTo($endDate)) {
            foreach ($days as $day) {
                $date = $month->copy()->day(min($day, $month->daysInMonth));

                if ($date->betweenIncluded($startDate->copy()->startOfDay(), $endDate)) {
                    $transactions[] = $this->makeProjectedTransaction($date);
                }
            }

            $month->addMonthNoOverflow();
        }

        return $transactions;
    }

    /**
     * Build a projected transaction array for this adjustment on the given date.
     */
    protected function makeProjectedTransaction(Carbon $date): array
    {
        return [
            'date' => $date->format('Y-m-d'),
            'amount_in_cents' => $this->amount_in_cents,
            'description' => $this->description ?? 'Scenario adjustment',
            'is_projected' => true,
            'is_scenario_adjustment' => true,
        ];
    }

    /**
     * Determine if a transaction should be generated for the given date.
     */
    protected function shouldGenerateForDate(Carbon $date): bool
    {
        // Check if date is within adjustment range
        if ($date->lessThan($this->start_date)) {
            return false;
        }

        if ($this->end_date && $date->greaterThan($this->end_date)) {
            return false;
        }

        switch ($this->frequency) {
            case 'daily':
                return true;

            case 'weekly':
                return $this->day_of_week !== null
                    ? $date->dayOfWeek === $this->day_of_week
                    : true;

            case 'biweekly':
                if ($this->day_of_week !== null && $date->dayOfWeek !== $this->day_of_week) {
                    return false;
                }
                $weekDiff = (int) $this->start_date->diffInWeeks($date);

                return $weekDiff % 2 === 0;

            case 'monthly':
                if ($this->day_of_month === null) {
                    return true;
                }
                // Handle edge cases where target day doesn't exist in month
                if ($date->daysInMonth >= $this->day_of_month) {
                    return $date->day == $this->day_of_month;
                }

                // If target day doesn't exist, use last day of month
                return $date->day == $date->daysInMonth;

            case 'quarterly':
                if ($this->day_of_month !== null) {
                    $daysInMonth = $date->daysInMonth;
                    $targetDay = $daysInMonth >= $this->day_of_month
                        ? $this->day_of_month
                        : $daysInMonth;
                    if ($date->day != $targetDay) {
                        return false;
                    }
                }
                $monthsSinceStart = (int) $this->start_date->diffInMonths($date);

                return $monthsSinceStart % 3 === 0;

            case 'yearly':
                if ($date->month !== $this->start_date->month) {
                    return false;
                }
                $targetDay = $this->start_date->day;
                $daysInMonth = $date->daysInMonth;
                $actualTargetDay = $daysInMonth >= $targetDay ? $targetDay : $daysInMonth;

                return $date->day === $actualTargetDay;

            default:
                return false;
        }
    }

    /**
     * Get the next date based on frequency.
     */
    protected function getNextDate(Carbon $fromDate): Carbon
    {
        switch ($this->frequency) {
            case 'daily':
                return $fromDate->copy()->addDay();

            case 'weekly':
                return $fromDate->copy()->addWeek();

            case 'biweekly':
                return $fromDate->copy()->addWeeks(2);

            case 'monthly':
                return $fromDate->copy()->addMonth();

            case 'quarterly':
                return $fromDate->copy()->addMonths(3);

            case 'yearly':
                return $fromDate->copy()->addYear();

            default:
                return $fromDate->copy()->addMonth(); // Default to monthly
        }
    }

    /**
     * Apply this adjustment to an existing transaction (for modify_existing type).
     *
     * @return array Modified transaction
     */
    public function applyToExistingTransaction(array $transaction): array
    {
        if ($this->adjustment_type !== self::TYPE_MODIFY_EXISTING) {
            return $transaction;
        }

        // Modify the transaction amount
        $transaction['amount_in_cents'] += $this->amount_in_cents;
        $transaction['modified_by_scenario'] = true;

        return $transaction;
    }
}
