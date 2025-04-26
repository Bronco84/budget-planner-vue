<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidAccount extends Model
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
        'plaid_account_id',
        'plaid_item_id',
        'institution_name',
        'current_balance_cents',
        'available_balance_cents',
        'balance_updated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'current_balance_cents' => 'integer',
        'available_balance_cents' => 'integer',
        'balance_updated_at' => 'datetime',
    ];

    /**
     * Get the budget that the Plaid account belongs to.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the account that the Plaid account belongs to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
} 