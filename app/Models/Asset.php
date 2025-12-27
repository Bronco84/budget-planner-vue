<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Asset extends Model
{
    use HasFactory;

    /**
     * Asset types
     */
    public const TYPE_PROPERTY = 'property';
    public const TYPE_VEHICLE = 'vehicle';
    public const TYPE_OTHER = 'other';

    /**
     * Property types
     */
    public const PROPERTY_TYPES = [
        'single_family' => 'Single Family Home',
        'condo' => 'Condominium',
        'townhouse' => 'Townhouse',
        'multi_family' => 'Multi-Family',
        'land' => 'Land',
        'other' => 'Other',
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
        'current_value_cents',
        'value_updated_at',
        'address',
        'property_type',
        'bedrooms',
        'bathrooms',
        'square_feet',
        'year_built',
        'vehicle_make',
        'vehicle_model',
        'vehicle_year',
        'vin',
        'mileage',
        'api_source',
        'api_id',
        'api_last_synced_at',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'current_value_cents' => 'integer',
        'value_updated_at' => 'datetime',
        'bedrooms' => 'integer',
        'bathrooms' => 'integer',
        'square_feet' => 'integer',
        'year_built' => 'integer',
        'vehicle_year' => 'integer',
        'mileage' => 'integer',
        'api_last_synced_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'current_value_dollars',
        'linked_loan_balance',
        'equity',
    ];

    /**
     * Get the budget that owns the asset.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the linked accounts (loans/mortgages) for this asset.
     */
    public function linkedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'asset_id');
    }

    /**
     * Get the primary linked loan account (if any).
     */
    public function primaryLoan(): ?Account
    {
        return $this->linkedAccounts()->first();
    }

    /**
     * Get current value in dollars.
     */
    protected function currentValueDollars(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->current_value_cents / 100,
        );
    }

    /**
     * Get the total balance of all linked loan accounts.
     */
    protected function linkedLoanBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->linkedAccounts->sum('current_balance_cents'),
        );
    }

    /**
     * Calculate equity (asset value - loan balance).
     */
    protected function equity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->current_value_cents - $this->linked_loan_balance,
        );
    }

    /**
     * Check if this is a property asset.
     */
    public function isProperty(): bool
    {
        return $this->type === self::TYPE_PROPERTY;
    }

    /**
     * Check if this is a vehicle asset.
     */
    public function isVehicle(): bool
    {
        return $this->type === self::TYPE_VEHICLE;
    }

    /**
     * Get a display name for the asset.
     */
    public function getDisplayName(): string
    {
        if ($this->isProperty() && $this->address) {
            return $this->name . ' (' . $this->address . ')';
        }

        if ($this->isVehicle() && $this->vehicle_year && $this->vehicle_make && $this->vehicle_model) {
            return $this->vehicle_year . ' ' . $this->vehicle_make . ' ' . $this->vehicle_model;
        }

        return $this->name;
    }

    /**
     * Get property type display name.
     */
    public function getPropertyTypeDisplay(): ?string
    {
        if (!$this->isProperty() || !$this->property_type) {
            return null;
        }

        return self::PROPERTY_TYPES[$this->property_type] ?? $this->property_type;
    }

    /**
     * Check if value needs updating (older than 30 days).
     */
    public function needsValueUpdate(): bool
    {
        if (!$this->value_updated_at) {
            return true;
        }

        return $this->value_updated_at->diffInDays(now()) > 30;
    }
}
