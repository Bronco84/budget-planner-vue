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
     * Check if this account is a credit card.
     */
    public function isCreditCard(): bool
    {
        return $this->account_type === 'credit' && $this->account_subtype === 'credit card';
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
} 