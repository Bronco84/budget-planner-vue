<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Account extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'name',
        'type',
        'current_balance_cents',
        'balance_updated_at',
        'include_in_budget',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'current_balance_cents' => 'integer',
        'balance_updated_at' => 'datetime',
        'include_in_budget' => 'boolean',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'status_label',
        'status_classes',
    ];

    /**
     * Get the budget that the account belongs to.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the transactions for the account.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the Plaid account information, if this was connected via Plaid.
     */
    public function plaidAccount(): HasOne
    {
        return $this->hasOne(PlaidAccount::class);
    }

    /**
     * Get all bank feeds for this account.
     */
    public function bankFeeds(): HasMany
    {
        return $this->hasMany(BankFeed::class);
    }

    /**
     * Get the active bank feeds for this account.
     */
    public function activeBankFeeds(): HasMany
    {
        return $this->bankFeeds()->where('status', BankFeed::STATUS_ACTIVE);
    }

    /**
     * Check if this account has any active bank feeds.
     */
    public function hasActiveBankFeeds(): bool
    {
        return $this->activeBankFeeds()->exists();
    }

    /**
     * Get the bank feed for a specific source type.
     */
    public function getBankFeedBySource(string $sourceType): ?BankFeed
    {
        return $this->bankFeeds()->where('source_type', $sourceType)->first();
    }

    public function scopeActive($query)
    {
        return $query->where('include_in_budget', true)->where('is_active', true);
    }

    /**
     * Get the account status based on include_in_budget.
     */
    protected function getStatusAttribute(): AccountStatus
    {
        return AccountStatus::fromIncludeInBudget($this->include_in_budget);
    }

    /**
     * Get the status label.
     */
    protected function statusLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getStatusAttribute()->label(),
        );
    }

    /**
     * Get the status CSS classes.
     */
    protected function statusClasses(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getStatusAttribute()->classes(),
        );
    }
}
