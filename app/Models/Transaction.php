<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;

class Transaction extends Model
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
        'date',
        'plaid_transaction_id',
        'is_plaid_imported',
        'is_reconciled',
        'recurring_transaction_template_id',
        'notes',
        'is_projected',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_in_cents' => 'integer',
        'date' => 'date:Y-m-d',
        'is_plaid_imported' => 'boolean',
        'is_reconciled' => 'boolean',
        'is_projected' => 'boolean',
    ];

    /**
     * Get the budget that owns the transaction.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the account that the transaction belongs to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the Plaid transaction data, if this was imported from Plaid.
     */
    public function plaidTransaction(): BelongsTo
    {
        return $this->belongsTo(PlaidTransaction::class, 'plaid_transaction_id', 'plaid_transaction_id');
    }

    /**
     * Get the recurring transaction template that generated this transaction.
     */
    public function recurringTemplate(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionTemplate::class, 'recurring_transaction_template_id');
    }

    /**
     * Get the recurring transaction template that this transaction is linked to.
     */
    public function recurringTransactionTemplate()
    {
        return $this->belongsTo(RecurringTransactionTemplate::class);
    }

    /**
     * Format amount for display.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '$' . number_format($this->amount_in_cents / 100, 2);
    }

    public function scopeFromFuture(\Illuminate\Database\Eloquent\Builder $query, string $date = null): \Illuminate\Database\Eloquent\Builder
    {
        return $query->when(
            isset($date),
            fn($query) => $query->where('date', '>=', $date),
            fn($query) => $query->where('date', '>=', now()->toDate()->format('Y-m-d'))
        );
    }
}
