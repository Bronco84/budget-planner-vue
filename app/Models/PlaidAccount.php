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
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'current_balance_cents' => 'integer',
        'available_balance_cents' => 'integer',
        'balance_updated_at' => 'datetime',
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
} 