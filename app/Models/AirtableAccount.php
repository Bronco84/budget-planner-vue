<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AirtableAccount extends Model
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
        'airtable_record_id',
        'institution_name',
        'account_name',
        'account_type',
        'account_subtype',
        'current_balance_cents',
        'available_balance_cents',
        'balance_updated_at',
        'account_number_last_4',
        'routing_number',
        'is_active',
        'external_account_id',
        'external_source',
        'fintable_metadata',
        'last_sync_at',
        'airtable_metadata',
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
        'is_active' => 'boolean',
        'fintable_metadata' => 'array',
        'last_sync_at' => 'datetime',
        'airtable_metadata' => 'array',
    ];

    /**
     * Get the budget that the Airtable account belongs to.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the account that the Airtable account belongs to.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the transactions for this Airtable account.
     */
    public function airtableTransactions(): HasMany
    {
        return $this->hasMany(AirtableTransaction::class, 'airtable_account_record_id', 'airtable_record_id');
    }

    /**
     * Get the current balance in dollars (convenience accessor).
     */
    public function getCurrentBalanceAttribute(): float
    {
        return $this->current_balance_cents / 100;
    }

    /**
     * Get the available balance in dollars (convenience accessor).
     */
    public function getAvailableBalanceAttribute(): ?float
    {
        return $this->available_balance_cents ? $this->available_balance_cents / 100 : null;
    }

    /**
     * Set the current balance from dollars (convenience mutator).
     */
    public function setCurrentBalanceAttribute(float $value): void
    {
        $this->attributes['current_balance_cents'] = round($value * 100);
    }

    /**
     * Set the available balance from dollars (convenience mutator).
     */
    public function setAvailableBalanceAttribute(?float $value): void
    {
        $this->attributes['available_balance_cents'] = $value ? round($value * 100) : null;
    }

    /**
     * Scope to get only active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get accounts by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('account_type', $type);
    }

    /**
     * Check if this account needs a balance update.
     */
    public function needsBalanceUpdate(): bool
    {
        if (!$this->balance_updated_at) {
            return true;
        }

        // Update if balance is older than 1 hour
        return $this->balance_updated_at->lt(now()->subHour());
    }

    /**
     * Get the display name for this account.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->account_name ?: 
               ($this->institution_name . ' ' . $this->account_type) ?: 
               'Airtable Account';
    }
}
