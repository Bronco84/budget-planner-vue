<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringTransactionTemplate extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'account_id',
        'description',
        'category',
        'amount_in_cents',
        'frequency',
        'start_date',
        'end_date',
        'day_of_week',
        'day_of_month',
        'is_dynamic_amount',
        'min_amount',
        'max_amount',
        'notes',
        'last_generated_date',
        'first_day_of_month',
        'average_amount',
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
        'is_dynamic_amount' => 'boolean',
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'last_generated_date' => 'datetime',
        'first_day_of_month' => 'integer',
        'average_amount' => 'float',
    ];

    /**
     * Frequency options.
     */
    const FREQUENCY_DAILY = 'daily';
    const FREQUENCY_WEEKLY = 'weekly';
    const FREQUENCY_BIWEEKLY = 'biweekly';
    const FREQUENCY_MONTHLY = 'monthly';
    const FREQUENCY_BIMONTHLY = 'bimonthly'; // Twice per month
    const FREQUENCY_QUARTERLY = 'quarterly';
    const FREQUENCY_YEARLY = 'yearly';

    /**
     * Get all available frequency options.
     *
     * @return array<string, string>
     */
    public static function getFrequencyOptions(): array
    {
        return [
            self::FREQUENCY_DAILY => 'Daily',
            self::FREQUENCY_WEEKLY => 'Weekly',
            self::FREQUENCY_BIWEEKLY => 'Every Two Weeks',
            self::FREQUENCY_MONTHLY => 'Monthly',
            self::FREQUENCY_BIMONTHLY => 'Twice a Month',
            self::FREQUENCY_QUARTERLY => 'Quarterly',
            self::FREQUENCY_YEARLY => 'Yearly',
        ];
    }

    /**
     * Get the budget that owns the recurring transaction template.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the account associated with the recurring transaction template.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the rules for this recurring transaction template.
     */
    public function rules(): HasMany
    {
        return $this->hasMany(RecurringTransactionRule::class);
    }

    /**
     * Get the transactions generated from this template.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recurring_transaction_template_id');
    }

    /**
     * Calculate the next occurrence date based on frequency and other parameters.
     *
     * @param \Carbon\Carbon|null $fromDate The date to calculate from, defaults to now
     * @return \Carbon\Carbon|null The next occurrence date, or null if no more occurrences
     */
    public function calculateNextOccurrence($fromDate = null)
    {
        $fromDate = $fromDate ?: now();

        // If we've passed the end date, there are no more occurrences
        if ($this->end_date && $fromDate->greaterThan($this->end_date)) {
            return null;
        }

        // Start from the start date if we're before it
        $baseDate = $fromDate->lessThan($this->start_date) ? $this->start_date : $fromDate;

        switch ($this->frequency) {
            case self::FREQUENCY_DAILY:
                return $baseDate->copy()->addDay();

            case self::FREQUENCY_WEEKLY:
                if ($this->day_of_week !== null) {
                    // Find the next occurrence of the specified day of week
                    $nextDate = $baseDate->copy();
                    while ($nextDate->dayOfWeek != $this->day_of_week) {
                        $nextDate->addDay();
                    }
                    return $nextDate;
                }
                return $baseDate->copy()->addWeek();

            case self::FREQUENCY_BIWEEKLY:
                return $baseDate->copy()->addWeeks(2);

            case self::FREQUENCY_MONTHLY:
                if ($this->day_of_month !== null) {
                    // Find the next occurrence of the day of month
                    $nextDate = $baseDate->copy()->addMonth();
                    $daysInMonth = $nextDate->daysInMonth;

                    // Handle cases where the day doesn't exist in the month
                    $day = min($this->day_of_month, $daysInMonth);

                    return $nextDate->setDay($day);
                }
                return $baseDate->copy()->addMonth();

            case self::FREQUENCY_BIMONTHLY:
                // Bi-monthly occurs on two specific days of the month (e.g., 1st and 15th)
                // The primary day is stored in day_of_month, the secondary day is stored in the first_day_of_month field
                // If first_day_of_month is null, default to 1st and 15th
                $firstDay = $this->first_day_of_month ?? 1;
                $secondDay = $this->day_of_month ?? 15;

                // Ensure first day is actually before second day
                if ($firstDay > $secondDay) {
                    $temp = $firstDay;
                    $firstDay = $secondDay;
                    $secondDay = $temp;
                }

                $nextDate = $baseDate->copy();
                $currentDay = $nextDate->day;

                // If we're before the first day of the month, move to the first day
                if ($currentDay < $firstDay) {
                    return $nextDate->setDay($firstDay);
                }

                // If we're between the first and second days, move to the second day
                if ($currentDay < $secondDay) {
                    return $nextDate->setDay($secondDay);
                }

                // Otherwise, we're past both days this month, move to the first day of next month
                return $nextDate->addMonth()->setDay($firstDay);

            case self::FREQUENCY_QUARTERLY:
                return $baseDate->copy()->addMonths(3);

            case self::FREQUENCY_YEARLY:
                return $baseDate->copy()->addYear();

            default:
                return null;
        }
    }

    /**
     * Get formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '$' . number_format($this->amount_in_cents / 100, 2);
    }

    /**
     * Determine if a transaction should be generated for the given date.
     *
     * @param \Carbon\Carbon $date
     * @return bool
     */
    public function shouldGenerateForDate(\Carbon\Carbon $date): bool
    {
        // Don't generate if before start date or after end date
        if ($date->startOfDay()->lt($this->start_date->startOfDay()) ||
            ($this->end_date && $date->startOfDay()->gt($this->end_date->startOfDay()))) {
            return false;
        }

        switch ($this->frequency) {
            case self::FREQUENCY_DAILY:
                return true;

            case self::FREQUENCY_WEEKLY:
                return $date->dayOfWeek === $this->day_of_week;

            case self::FREQUENCY_BIWEEKLY:
                // Must be the right day of the week
                if ($date->dayOfWeek !== $this->day_of_week) {
                    return false;
                }

                // Must be an even number of weeks from the start date
                $weekDiff = $this->start_date->diffInWeeks($date);
                return $weekDiff % 2 === 0;

            case self::FREQUENCY_MONTHLY:
                return $this->day_of_month === null || $date->day == $this->day_of_month;

            case self::FREQUENCY_BIMONTHLY:
                // Bi-monthly occurs on two specific days of the month
                $firstDay = $this->first_day_of_month ?? 1;
                $secondDay = $this->day_of_month ?? 15;

                // Ensure first day is before second day
                if ($firstDay > $secondDay) {
                    $temp = $firstDay;
                    $firstDay = $secondDay;
                    $secondDay = $temp;
                }

                // Check if today matches either of the specified days
                return $date->day === $firstDay || $date->day === $secondDay;

            case self::FREQUENCY_QUARTERLY:
                // Check if it's the right day of the month
                if ($this->day_of_month !== null && $date->day != $this->day_of_month) {
                    return false;
                }

                // Check if it's a quarter month (Jan, Apr, Jul, Oct)
                $monthsSinceStart = $this->start_date->diffInMonths($date);
                return $monthsSinceStart % 3 === 0;

            case self::FREQUENCY_YEARLY:
                // Check if it's the anniversary date (same month and day)
                return $date->format('m-d') === $this->start_date->format('m-d');

            default:
                return false;
        }
    }

    /**
     * Get the next date for this template based on frequency and the given date.
     *
     * @param \Carbon\Carbon $fromDate The date to calculate from
     * @return \Carbon\Carbon The next date
     */
    public function getNextDate($fromDate)
    {
        switch ($this->frequency) {
            case self::FREQUENCY_DAILY:
                return $fromDate->copy()->addDay();

            case self::FREQUENCY_WEEKLY:
                return $fromDate->copy()->addWeek();

            case self::FREQUENCY_BIWEEKLY:
                return $fromDate->copy()->addWeeks(2);

            case self::FREQUENCY_MONTHLY:
                return $fromDate->copy()->addMonth();

            case self::FREQUENCY_BIMONTHLY:
                // Implementation needed
                return null;

            case self::FREQUENCY_QUARTERLY:
                return $fromDate->copy()->addMonths(3);

            case self::FREQUENCY_YEARLY:
                return $fromDate->copy()->addYear();

            default:
                return $fromDate->copy()->addMonth(); // Default to monthly
        }
    }

    /**
     * Calculate the dynamic amount for this recurring transaction template.
     *
     * @return float The calculated amount in dollars
     */
    public function calculateDynamicAmount(): float
    {
        // If average_amount is set, use it (assume it's stored in dollars)
        if (!is_null($this->average_amount)) {
            return (float) $this->average_amount;
        }
        // If both min and max are set, use their midpoint (assume stored in dollars)
        if (!is_null($this->min_amount) && !is_null($this->max_amount)) {
            return (float) (($this->min_amount + $this->max_amount) / 2);
        }
        // Fallback to static amount (convert from cents to dollars)
        return $this->amount_in_cents / 100;
    }
}
