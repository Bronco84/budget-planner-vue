<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Carbon\Carbon;
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
     * Account types that are considered liabilities.
     */
    public const LIABILITY_TYPES = [
        'mortgage',
        'line of credit',
        'credit',
        'credit card',
        'loan'
    ];

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
        'exclude_from_total_balance',
        'autopay_enabled',
        'autopay_source_account_id',
        'autopay_amount_override_cents',
        'property_id',
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
        'exclude_from_total_balance' => 'boolean',
        'autopay_enabled' => 'boolean',
        'autopay_source_account_id' => 'integer',
        'autopay_amount_override_cents' => 'integer',
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

    /**
     * Get the source account that funds autopay for this credit card.
     */
    public function autopaySourceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'autopay_source_account_id');
    }

    /**
     * Get credit cards that are funded by this account via autopay.
     */
    public function autopayTargetAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'autopay_source_account_id');
    }

    /**
     * Get the property that this account (loan/mortgage) is linked to.
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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
        return AccountStatus::fromIncludeInBudget($this->include_in_budget ?? true);
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

    /**
     * Check if this account type is a liability (should be subtracted from total balance).
     */
    public function isLiability(): bool
    {
        return in_array($this->type, self::LIABILITY_TYPES);
    }

    /**
     * Check if this account type is an asset (should be added to total balance).
     */
    public function isAsset(): bool
    {
        return !$this->isLiability();
    }

    /**
     * Check if this account has autopay configured and active.
     */
    public function hasActiveAutopay(): bool
    {
        return $this->autopay_enabled
            && $this->autopay_source_account_id !== null
            && $this->plaidAccount?->hasLiabilityData();
    }

    /**
     * Get the autopay payment amount (override or statement balance).
     */
    public function getAutopayAmountCents(): ?int
    {
        if (!$this->hasActiveAutopay()) {
            return null;
        }

        // Use manual override if set, otherwise use statement balance
        return $this->autopay_amount_override_cents
            ?? $this->plaidAccount?->last_statement_balance_cents;
    }

    /**
     * Get the next autopay payment date.
     */
    public function getNextAutopayDate(): ?Carbon
    {
        if (!$this->hasActiveAutopay()) {
            return null;
        }

        return $this->plaidAccount?->next_payment_due_date
            ? Carbon::parse($this->plaidAccount->next_payment_due_date)
            : null;
    }

    /**
     * Check if this account can be used as an autopay source.
     */
    public function canBeAutopaySource(): bool
    {
        return $this->plaidAccount?->account_type === 'depository'
            && in_array($this->plaidAccount?->account_subtype, ['checking', 'savings']);
    }

    /**
     * Check if this is a credit card eligible for autopay.
     */
    public function isAutopayEligible(): bool
    {
        return $this->plaidAccount?->isCreditCard()
            && $this->plaidAccount?->hasLiabilityData();
    }
}
