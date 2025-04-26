<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'amount',
        'date',
        'plaid_transaction_id',
        'is_plaid_imported',
        'is_reconciled',
        'recurring_transaction_template_id',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'is_plaid_imported' => 'boolean',
        'is_reconciled' => 'boolean',
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
        return $this->belongsTo(PlaidTransaction::class);
    }
} 