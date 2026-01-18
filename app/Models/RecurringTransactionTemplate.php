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
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // When deleting a template, unlink any associated transactions
        static::deleting(function (RecurringTransactionTemplate $template) {
            // Clear the recurring_transaction_template_id on all linked transactions
            Transaction::where('recurring_transaction_template_id', $template->id)
                ->update(['recurring_transaction_template_id' => null]);
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'account_id',
        'linked_credit_card_account_id',
        'plaid_entity_id',
        'plaid_entity_name',
        'description',
        'friendly_label',
        'category',
        'amount_in_cents',
        'frequency',
        'start_date',
        'end_date',
        'day_of_week',
        'day_of_month',
        'first_day_of_month',
        'is_dynamic_amount',
        'min_amount',
        'max_amount',
        'notes',
        'last_generated_date',
        'average_amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_in_cents' => 'integer',
        'linked_credit_card_account_id' => 'integer',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'first_day_of_month' => 'integer',
        'is_dynamic_amount' => 'boolean',
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'last_generated_date' => 'datetime',
        'average_amount' => 'float',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'formatted_amount',
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
     * Get the linked credit card account for autopay override.
     */
    public function linkedCreditCard(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'linked_credit_card_account_id');
    }

    /**
     * Check if autopay should override the projection for a given date.
     * 
     * Returns true if:
     * - This template has a linked credit card
     * - The linked credit card has active autopay
     * - The projection date is in the future (autopay will handle all future payments)
     *
     * @param \Carbon\Carbon $date
     * @return bool
     */
    public function shouldAutopayOverrideFor(\Carbon\Carbon $date): bool
    {
        if (!$this->linked_credit_card_account_id) {
            return false;
        }

        $linkedCard = $this->linkedCreditCard;
        if (!$linkedCard?->hasActiveAutopay()) {
            return false;
        }

        // If autopay is active, it should handle ALL future projections
        // Only generate recurring projections for past dates (historical tracking)
        return $date->isFuture() || $date->isToday();
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

                    // Handle edge cases where the target day doesn't exist (29th-31st → Feb 28th/29th, etc.)
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
                $daysInMonth = $nextDate->daysInMonth;

                // Adjust target days if they don't exist in this month (29th-31st → Feb 28th/29th, etc.)
                $targetFirstDay = min($firstDay, $daysInMonth);
                $targetSecondDay = min($secondDay, $daysInMonth);

                // If we're before the first day of the month, move to the first day
                if ($currentDay < $targetFirstDay) {
                    return $nextDate->setDay($targetFirstDay);
                }

                // If we're between the first and second days, move to the second day
                if ($currentDay < $targetSecondDay) {
                    return $nextDate->setDay($targetSecondDay);
                }

                // Otherwise, we're past both days this month, move to the first day of next month
                $nextMonthDate = $nextDate->addMonth();
                $nextMonthDays = $nextMonthDate->daysInMonth;
                $nextMonthFirstDay = min($firstDay, $nextMonthDays);
                return $nextMonthDate->setDay($nextMonthFirstDay);

            case self::FREQUENCY_QUARTERLY:
                if ($this->day_of_month !== null) {
                    $nextDate = $baseDate->copy()->addMonths(3);
                    $daysInMonth = $nextDate->daysInMonth;
                    // Handle edge cases where target day doesn't exist (29th-31st → Feb 28th/29th, etc.)
                    $day = min($this->day_of_month, $daysInMonth);
                    return $nextDate->setDay($day);
                }
                return $baseDate->copy()->addMonths(3);

            case self::FREQUENCY_YEARLY:
                $nextDate = $baseDate->copy()->addYear();
                // Handle leap year edge case (Feb 29th on non-leap years)
                if ($this->start_date->month == 2 && $this->start_date->day == 29 && !$nextDate->isLeapYear()) {
                    return $nextDate->setMonth(2)->setDay(28);
                }
                // Handle day-of-month edge cases (29th-31st in shorter months)
                $targetMonth = $this->start_date->month;
                $targetDay = $this->start_date->day;
                $nextDate->setMonth($targetMonth);
                $daysInMonth = $nextDate->daysInMonth;
                $day = min($targetDay, $daysInMonth);
                return $nextDate->setDay($day);

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
     * Check if a transaction exists for the given date.
     * Uses preloaded relationship if available, otherwise falls back to query.
     *
     * @param \Carbon\Carbon $date
     * @return bool
     */
    public function hasTransactionForDate(\Carbon\Carbon $date): bool
    {
        $dateString = $date->copy()->format('Y-m-d');
        
        // If transactions are loaded, use the collection
        if ($this->relationLoaded('transactions')) {
            return $this->transactions->contains(function ($transaction) use ($dateString) {
                return $transaction->date && $transaction->date->format('Y-m-d') === $dateString;
            });
        }
        
        // Fallback to database query if relationship not loaded
        return $this->transactions()->where('date', $dateString)->exists();
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
        if (
            $date->startOfDay()->lt($this->start_date->startOfDay()) ||
            ($this->end_date && $date->startOfDay()->gt($this->end_date->startOfDay())) ||
            $this->hasTransactionForDate($date)
        ) {
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
                if ($this->day_of_month === null) {
                    return true;
                }

                // If the target day exists in this month, check for exact match
                if ($date->daysInMonth >= $this->day_of_month) {
                    return $date->day == $this->day_of_month;
                }

                // If the target day doesn't exist in this month (29th-31st), use the last day of the month
                // Examples: 31st → Feb 28th/29th, 30th → Feb 28th/29th, 29th → Feb 28th (non-leap years)
                return $date->day == $date->daysInMonth;

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

                $daysInMonth = $date->daysInMonth;
                $currentDay = $date->day;

                // Check if today matches the first day (or last day if first day doesn't exist in this month)
                // Handles edge cases: 29th-31st → Feb 28th/29th, etc.
                $targetFirstDay = $daysInMonth >= $firstDay ? $firstDay : $daysInMonth;
                if ($currentDay === $targetFirstDay) {
                    return true;
                }

                // Check if today matches the second day (or last day if second day doesn't exist in this month)
                // Handles edge cases: 29th-31st → Feb 28th/29th, etc.
                $targetSecondDay = $daysInMonth >= $secondDay ? $secondDay : $daysInMonth;
                if ($currentDay === $targetSecondDay) {
                    return true;
                }

                return false;

            case self::FREQUENCY_QUARTERLY:
                // Check if it's the right day of the month
                if ($this->day_of_month !== null) {
                    $daysInMonth = $date->daysInMonth;
                    // Handle edge cases where target day doesn't exist (29th-31st → Feb 28th/29th, etc.)
                    $targetDay = $daysInMonth >= $this->day_of_month ? $this->day_of_month : $daysInMonth;

                    if ($date->day != $targetDay) {
                        return false;
                    }
                }

                // Check if it's a quarter month (Jan, Apr, Jul, Oct)
                $monthsSinceStart = $this->start_date->diffInMonths($date);
                return $monthsSinceStart % 3 === 0;

            case self::FREQUENCY_YEARLY:
                // Check if it's the anniversary date (same month and day)
                if ($date->month !== $this->start_date->month) {
                    return false;
                }

                $targetDay = $this->start_date->day;
                $daysInMonth = $date->daysInMonth;
                // Handle edge cases: Feb 29th on non-leap years, 29th-31st in shorter months
                $actualTargetDay = $daysInMonth >= $targetDay ? $targetDay : $daysInMonth;

                return $date->day === $actualTargetDay;

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
                // Bi-monthly: move to the next occurrence within the current or next month
                $firstDay = $this->first_day_of_month ?? 1;
                $secondDay = $this->day_of_month ?? 15;

                // Ensure first day is before second day
                if ($firstDay > $secondDay) {
                    $temp = $firstDay;
                    $firstDay = $secondDay;
                    $secondDay = $temp;
                }

                $nextDate = $fromDate->copy();
                $currentDay = $nextDate->day;
                $daysInMonth = $nextDate->daysInMonth;

                // Adjust target days if they don't exist in this month (29th-31st → Feb 28th/29th, etc.)
                $targetFirstDay = min($firstDay, $daysInMonth);
                $targetSecondDay = min($secondDay, $daysInMonth);

                // If we're before the first day of the month, move to the first day
                if ($currentDay < $targetFirstDay) {
                    return $nextDate->setDay($targetFirstDay);
                }

                // If we're between the first and second days, move to the second day
                if ($currentDay < $targetSecondDay) {
                    return $nextDate->setDay($targetSecondDay);
                }

                // Otherwise, we're past both days this month, move to the first day of next month
                $nextMonthDate = $nextDate->addMonth();
                $nextMonthDays = $nextMonthDate->daysInMonth;
                $nextMonthFirstDay = min($firstDay, $nextMonthDays);
                return $nextMonthDate->setDay($nextMonthFirstDay);

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
