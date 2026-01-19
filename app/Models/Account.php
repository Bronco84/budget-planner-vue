<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Services\BudgetService;
use App\Traits\InstitutionDomainMapping;
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
    use InstitutionDomainMapping;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Clear projection caches when autopay settings change
        static::updated(function (Account $account) {
            // Check if autopay-related fields changed
            $autopayFields = ['autopay_enabled', 'autopay_source_account_id', 'autopay_amount_override_cents', 'current_balance_cents'];
            $autopayChanged = false;
            
            foreach ($autopayFields as $field) {
                if ($account->isDirty($field)) {
                    $autopayChanged = true;
                    break;
                }
            }
            
            if ($autopayChanged) {
                // Clear caches for this account
                BudgetService::clearAccountCaches($account->id);
                
                // If this is an autopay source, also clear caches for target accounts
                if ($account->isDirty('autopay_source_account_id')) {
                    $oldSourceId = $account->getOriginal('autopay_source_account_id');
                    if ($oldSourceId) {
                        BudgetService::clearAccountCaches($oldSourceId);
                    }
                    if ($account->autopay_source_account_id) {
                        BudgetService::clearAccountCaches($account->autopay_source_account_id);
                    }
                }
            }
        });
    }

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
        'custom_logo',
        'logo_url',
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
        'logo_src',
        'institution_name',
        'initials',
        'initials_bg_color',
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

    /**
     * Get the best available logo source URL.
     *
     * Priority:
     * 1. custom_logo (base64 or URL)
     * 2. logo_url (external URL)
     * 3. Google S2 favicon from institution_url
     * 4. Google S2 favicon from institution name domain mapping
     * 5. null (frontend will show initials fallback)
     */
    protected function logoSrc(): Attribute
    {
        return Attribute::make(
            get: function () {
                // 1. Custom logo (highest priority - user uploaded)
                if ($this->custom_logo) {
                    // If it's already a full URL or data URI, use as-is
                    if (str_starts_with($this->custom_logo, 'http') || str_starts_with($this->custom_logo, 'data:')) {
                        return $this->custom_logo;
                    }
                    // Otherwise assume it's base64
                    return 'data:image/png;base64,' . $this->custom_logo;
                }

                // 2. External logo URL (fetched from Clearbit, etc.)
                if ($this->logo_url) {
                    return $this->logo_url;
                }

                // 3. Try to get favicon from Plaid institution URL
                $institutionUrl = $this->plaidAccount?->plaidConnection?->institution_url;
                if ($institutionUrl) {
                    $domain = static::extractDomainFromUrl($institutionUrl);
                    if ($domain) {
                        return static::getGoogleFaviconUrl($domain);
                    }
                }

                // 4. Try to get favicon from institution name domain mapping
                $institutionName = $this->resolveInstitutionName();
                $faviconUrl = static::getFaviconUrlForInstitution($institutionName);
                if ($faviconUrl) {
                    return $faviconUrl;
                }

                // 5. No logo available - frontend will show initials fallback
                return null;
            }
        );
    }

    /**
     * Get the institution name for this account.
     * Returns Plaid institution name if available, otherwise the account name.
     */
    protected function institutionName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->plaidAccount?->plaidConnection?->institution_name ?? $this->name
        );
    }

    /**
     * Get 2-letter initials from the institution name.
     */
    protected function initials(): Attribute
    {
        return Attribute::make(
            get: function () {
                $name = $this->resolveInstitutionName();

                if (!$name) {
                    return '?';
                }

                $words = preg_split('/\s+/', trim($name));

                if (count($words) >= 2) {
                    return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
                }

                return strtoupper(substr($name, 0, 2));
            }
        );
    }

    /**
     * Get a consistent Tailwind background color class based on the institution name.
     */
    protected function initialsBgColor(): Attribute
    {
        return Attribute::make(
            get: function () {
                $colors = [
                    'bg-blue-500',
                    'bg-emerald-500',
                    'bg-violet-500',
                    'bg-amber-500',
                    'bg-rose-500',
                    'bg-cyan-500',
                    'bg-indigo-500',
                    'bg-teal-500',
                    'bg-orange-500',
                    'bg-pink-500',
                ];

                // Simple hash of the name to get consistent color
                $name = $this->resolveInstitutionName() ?? '';
                
                // Use a simple string hash that won't overflow
                $hash = crc32($name);
                
                // crc32 can return negative values, so use abs
                return $colors[abs($hash) % count($colors)];
            }
        );
    }

    /**
     * Helper to resolve the institution name value.
     */
    private function resolveInstitutionName(): ?string
    {
        return $this->plaidAccount?->plaidConnection?->institution_name ?? $this->name;
    }
}
