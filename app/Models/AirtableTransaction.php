<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class AirtableTransaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'airtable_record_id',
        'airtable_account_record_id',
        'amount',
        'date',
        'datetime',
        'description',
        'category',
        'pending',
        'transaction_type',
        'payment_method',
        'merchant_name',
        'merchant_category',
        'external_transaction_id',
        'external_source',
        'fintable_metadata',
        'primary_category',
        'detailed_category',
        'category_metadata',
        'location',
        'merchant_logo_url',
        'merchant_website',
        'iso_currency_code',
        'unofficial_currency_code',
        'pending_transaction_id',
        'transfer_account_id',
        'last_sync_at',
        'airtable_metadata',
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
        'pending' => 'boolean',
        'fintable_metadata' => 'array',
        'category_metadata' => 'array',
        'location' => 'array',
        'last_sync_at' => 'datetime',
        'airtable_metadata' => 'array',
    ];

    /**
     * Get the account that this transaction belongs to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the airtable account this transaction belongs to.
     */
    public function airtableAccount(): BelongsTo
    {
        return $this->belongsTo(AirtableAccount::class, 'airtable_account_record_id', 'airtable_record_id');
    }

    /**
     * Scope to get pending transactions.
     */
    public function scopePending($query)
    {
        return $query->where('pending', true);
    }

    /**
     * Scope to get settled transactions.
     */
    public function scopeSettled($query)
    {
        return $query->where('pending', false);
    }

    /**
     * Scope to get transactions by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    /**
     * Scope to get transactions by category.
     */
    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to get transactions in date range.
     */
    public function scopeBetweenDates($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to get transactions by merchant.
     */
    public function scopeByMerchant($query, string $merchantName)
    {
        return $query->where('merchant_name', 'like', "%{$merchantName}%");
    }

    /**
     * Get the amount in cents (for consistency with app's financial handling).
     */
    public function getAmountInCentsAttribute(): int
    {
        return round($this->amount * 100);
    }

    /**
     * Set the amount from cents.
     */
    public function setAmountFromCents(int $cents): void
    {
        $this->amount = $cents / 100;
    }

    /**
     * Check if this is a debit transaction.
     */
    public function isDebit(): bool
    {
        return $this->amount < 0 || $this->transaction_type === 'debit';
    }

    /**
     * Check if this is a credit transaction.
     */
    public function isCredit(): bool
    {
        return $this->amount > 0 || $this->transaction_type === 'credit';
    }

    /**
     * Get the absolute amount (always positive).
     */
    public function getAbsoluteAmountAttribute(): float
    {
        return abs($this->amount);
    }

    /**
     * Get the display description for this transaction.
     */
    public function getDisplayDescriptionAttribute(): string
    {
        return $this->merchant_name ?: $this->description ?: 'Transaction';
    }

    /**
     * Get the effective category (primary or fallback).
     */
    public function getEffectiveCategoryAttribute(): string
    {
        return $this->primary_category ?: $this->category ?: 'Uncategorized';
    }

    /**
     * Check if this transaction has location data.
     */
    public function hasLocation(): bool
    {
        return !empty($this->location) && is_array($this->location);
    }

    /**
     * Convert this transaction to the format expected by the main Transaction model.
     */
    public function toTransactionFormat(): array
    {
        return [
            'description' => $this->display_description,
            'category' => $this->effective_category,
            'amount_in_cents' => $this->amount_in_cents,
            'date' => $this->date,
            'airtable_transaction_id' => $this->airtable_record_id,
            'is_airtable_imported' => true,
            'metadata' => [
                'merchant_name' => $this->merchant_name,
                'payment_method' => $this->payment_method,
                'transaction_type' => $this->transaction_type,
                'location' => $this->location,
                'external_transaction_id' => $this->external_transaction_id,
                'airtable_metadata' => $this->airtable_metadata,
            ]
        ];
    }
}
