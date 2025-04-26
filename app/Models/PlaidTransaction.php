<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlaidTransaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plaid_transaction_id',
        'plaid_account_id',
        'pending',
        'amount',
        'date',
        'name',
        'merchant_name',
        'payment_channel',
        'category',
        'logo_url',
        'website',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'pending' => 'boolean',
    ];

    /**
     * Get the transaction that this Plaid transaction is associated with.
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }
} 