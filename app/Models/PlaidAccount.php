<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class PlaidAccount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'plaid_connection_id',
        'account_id',
        'plaid_account_id',
        'account_name',
        'account_type',
        'account_subtype',
        'account_mask',
        'current_balance_cents',
        'available_balance_cents',
        'balance_updated_at',
        'last_statement_balance_cents',
        'last_statement_issue_date',
        'last_payment_amount_cents',
        'last_payment_date',
        'next_payment_due_date',
        'minimum_payment_amount_cents',
        'apr_percentage',
        'credit_limit_cents',
        'liability_updated_at',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = ['institution_name'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'current_balance_cents' => 'integer',
        'available_balance_cents' => 'integer',
        'balance_updated_at' => 'datetime',
        'last_statement_balance_cents' => 'integer',
        'last_statement_issue_date' => 'date',
        'last_payment_amount_cents' => 'integer',
        'last_payment_date' => 'date',
        'next_payment_due_date' => 'date',
        'minimum_payment_amount_cents' => 'integer',
        'apr_percentage' => 'decimal:2',
        'credit_limit_cents' => 'integer',
        'liability_updated_at' => 'datetime',
    ];

    /**
     * Get the Plaid connection that this account belongs to.
     */
    public function plaidConnection(): BelongsTo
    {
        return $this->belongsTo(PlaidConnection::class);
    }

    /**
     * Get the budget account that this Plaid account is linked to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the budget through the Plaid connection.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id', 'id')
                    ->through('plaidConnection');
    }

    /**
     * Helper to get institution name through connection.
     */
    public function getInstitutionNameAttribute(): string
    {
        return $this->plaidConnection->institution_name ?? 'Unknown Institution';
    }

    /**
     * Helper to get access token through connection.
     */
    public function getAccessTokenAttribute(): string
    {
        return $this->plaidConnection->access_token ?? '';
    }

    /**
     * Get the statement history for this Plaid account.
     */
    public function statementHistory(): HasMany
    {
        return $this->hasMany(PlaidStatementHistory::class);
    }

    /**
     * Get the investment holdings for this Plaid account.
     */
    public function holdings(): HasMany
    {
        return $this->hasMany(PlaidHolding::class);
    }

    /**
     * Check if this account is a credit card.
     */
    public function isCreditCard(): bool
    {
        return $this->account_type === 'credit' && $this->account_subtype === 'credit card';
    }

    /**
     * Investment account subtypes from Plaid.
     */
    public const INVESTMENT_SUBTYPES = [
        'brokerage',
        '401a',
        '401k',
        '403b',
        '457b',
        '529',
        'cash isa',
        'crypto exchange',
        'education savings account',
        'fixed annuity',
        'gic',
        'health reimbursement arrangement',
        'hsa',
        'ira',
        'isa',
        'keogh',
        'lif',
        'life insurance',
        'lira',
        'lrif',
        'lrsp',
        'mutual fund',
        'non-custodial wallet',
        'non-taxable brokerage account',
        'other',
        'other annuity',
        'other insurance',
        'pension',
        'prif',
        'profit sharing plan',
        'qshr',
        'rdsp',
        'resp',
        'retirement',
        'rlif',
        'roth',
        'roth 401k',
        'rrif',
        'rrsp',
        'sarsep',
        'sep ira',
        'simple ira',
        'sipp',
        'stock plan',
        'tfsa',
        'trust',
        'ugma',
        'utma',
        'variable annuity',
    ];

    /**
     * Check if this account is an investment account.
     */
    public function isInvestmentAccount(): bool
    {
        // Check main type first
        if ($this->account_type === 'investment') {
            return true;
        }

        // Also check if subtype is an investment subtype (in case type wasn't set correctly)
        return in_array(strtolower($this->account_subtype ?? ''), self::INVESTMENT_SUBTYPES);
    }

    /**
     * Check if this account has investment holdings data.
     */
    public function hasHoldingsData(): bool
    {
        return $this->holdings()->exists();
    }

    /**
     * Get the total market value of all holdings in this account.
     */
    public function getTotalHoldingsValueCents(): int
    {
        return $this->holdings()->sum('institution_value_cents') ?? 0;
    }

    /**
     * Check if this account has liability data.
     */
    public function hasLiabilityData(): bool
    {
        return $this->last_statement_balance_cents !== null;
    }

    /**
     * Calculate the number of days until the next payment is due.
     */
    public function getDaysUntilPaymentDue(): ?int
    {
        if (!$this->next_payment_due_date) {
            return null;
        }

        return Carbon::now()->diffInDays($this->next_payment_due_date, false);
    }

    /**
     * Get the statement cycle length in days.
     * Infers from statement history if available, otherwise defaults to 30 days.
     *
     * @return int Number of days in a typical statement cycle
     */
    public function getStatementCycleLength(): int
    {
        // Try to infer from statement history
        $statements = $this->statementHistory()
            ->orderBy('statement_issue_date', 'desc')
            ->limit(6)
            ->get();

        if ($statements->count() >= 2) {
            // Calculate average days between consecutive statements
            $totalDays = 0;
            $intervals = 0;

            for ($i = 0; $i < $statements->count() - 1; $i++) {
                $current = Carbon::parse($statements[$i]->statement_issue_date);
                $previous = Carbon::parse($statements[$i + 1]->statement_issue_date);
                $daysBetween = $previous->diffInDays($current);
                
                // Only count reasonable intervals (20-40 days)
                if ($daysBetween >= 20 && $daysBetween <= 40) {
                    $totalDays += $daysBetween;
                    $intervals++;
                }
            }

            if ($intervals > 0) {
                return (int) round($totalDays / $intervals);
            }
        }

        // Default to 30 days
        return 30;
    }

    /**
     * Get the number of days since the last statement was issued.
     *
     * @return int|null Number of days since statement, or null if no statement date
     */
    public function getDaysSinceStatement(): ?int
    {
        if (!$this->last_statement_issue_date) {
            return null;
        }

        return Carbon::parse($this->last_statement_issue_date)->diffInDays(Carbon::now());
    }

    /**
     * Get the estimated number of days remaining in the current statement cycle.
     *
     * @return int|null Days remaining, or null if no statement date
     */
    public function getDaysRemainingInCycle(): ?int
    {
        $daysSince = $this->getDaysSinceStatement();
        
        if ($daysSince === null) {
            return null;
        }

        $cycleLength = $this->getStatementCycleLength();
        $remaining = $cycleLength - $daysSince;

        // If we're past the cycle length, the next statement should be imminent
        return max(0, $remaining);
    }

    /**
     * Get the estimated next statement close date.
     *
     * @return Carbon|null
     */
    public function getEstimatedNextStatementDate(): ?Carbon
    {
        if (!$this->last_statement_issue_date) {
            return null;
        }

        $cycleLength = $this->getStatementCycleLength();
        return Carbon::parse($this->last_statement_issue_date)->addDays($cycleLength);
    }
} 