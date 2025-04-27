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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_in_cents' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'is_dynamic_amount' => 'boolean',
        'min_amount' => 'integer',
        'max_amount' => 'integer',
    ];

    /**
     * Frequency options.
     */
    const FREQUENCY_DAILY = 'daily';
    const FREQUENCY_WEEKLY = 'weekly';
    const FREQUENCY_BIWEEKLY = 'biweekly';
    const FREQUENCY_MONTHLY = 'monthly';
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
                
            case self::FREQUENCY_QUARTERLY:
                return $baseDate->copy()->addMonths(3);
                
            case self::FREQUENCY_YEARLY:
                return $baseDate->copy()->addYear();
                
            default:
                return $baseDate->copy()->addMonth(); // Default to monthly
        }
    }

    /**
     * Get formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '$' . number_format($this->amount_in_cents / 100, 2);
    }
} 