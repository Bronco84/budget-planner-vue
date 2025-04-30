<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidTransaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'transaction_id',
        'plaid_transaction_id',
        'plaid_account_id',
        'amount',
        'date',
        'datetime',
        'authorized_date',
        'authorized_datetime',
        'name',
        'merchant_name',
        'merchant_entity_id',
        'payment_channel',
        'transaction_code',
        'transaction_type',
        'pending',
        'pending_transaction_id',
        'iso_currency_code',
        'unofficial_currency_code',
        'check_number',
        'logo_url',
        'website',
        'category',
        'category_id',
        'counterparties',
        'location',
        'payment_meta',
        'personal_finance_category',
        'personal_finance_category_icon_url',
        'metadata',
        'original_data',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'datetime' => 'datetime',
        'authorized_date' => 'date',
        'authorized_datetime' => 'datetime',
        'pending' => 'boolean',
        'counterparties' => 'array',
        'location' => 'array',
        'payment_meta' => 'array',
        'personal_finance_category' => 'array',
        'metadata' => 'array',
        'original_data' => 'array',
    ];

    /**
     * Get the transaction that this Plaid transaction is associated with.
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    /**
     * Get the account that this Plaid transaction belongs to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
} 