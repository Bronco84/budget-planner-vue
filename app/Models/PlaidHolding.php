<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidHolding extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'plaid_account_id',
        'plaid_security_id',
        'plaid_account_identifier',
        'quantity',
        'cost_basis_cents',
        'institution_price_cents',
        'institution_price_as_of',
        'institution_value_cents',
        'iso_currency_code',
        'unofficial_currency_code',
        'original_data',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'quantity' => 'decimal:8',
        'cost_basis_cents' => 'integer',
        'institution_price_cents' => 'integer',
        'institution_price_as_of' => 'date',
        'institution_value_cents' => 'integer',
        'original_data' => 'array',
    ];

    /**
     * Get the Plaid account that owns this holding.
     */
    public function plaidAccount(): BelongsTo
    {
        return $this->belongsTo(PlaidAccount::class);
    }

    /**
     * Get the security for this holding.
     */
    public function security(): BelongsTo
    {
        return $this->belongsTo(PlaidSecurity::class, 'plaid_security_id');
    }

    /**
     * Get the cost basis in dollars.
     */
    public function getCostBasisAttribute(): ?float
    {
        return $this->cost_basis_cents ? $this->cost_basis_cents / 100 : null;
    }

    /**
     * Get the institution price in dollars.
     */
    public function getInstitutionPriceAttribute(): ?float
    {
        return $this->institution_price_cents ? $this->institution_price_cents / 100 : null;
    }

    /**
     * Get the institution value (market value) in dollars.
     */
    public function getInstitutionValueAttribute(): ?float
    {
        return $this->institution_value_cents ? $this->institution_value_cents / 100 : null;
    }

    /**
     * Calculate the current market value based on quantity and security close price.
     */
    public function getCalculatedMarketValueAttribute(): ?float
    {
        if (!$this->security || !$this->security->close_price_cents) {
            return null;
        }

        return ($this->quantity * $this->security->close_price_cents) / 100;
    }

    /**
     * Calculate the gain/loss in dollars.
     */
    public function getGainLossAttribute(): ?float
    {
        if ($this->institution_value_cents === null || $this->cost_basis_cents === null) {
            return null;
        }

        return ($this->institution_value_cents - $this->cost_basis_cents) / 100;
    }

    /**
     * Calculate the gain/loss percentage.
     */
    public function getGainLossPercentAttribute(): ?float
    {
        if ($this->cost_basis_cents === null || $this->cost_basis_cents === 0) {
            return null;
        }

        $gainLoss = $this->institution_value_cents - $this->cost_basis_cents;
        return ($gainLoss / $this->cost_basis_cents) * 100;
    }

    /**
     * Create or update a holding from Plaid API data.
     *
     * @param PlaidAccount $plaidAccount The Plaid account this holding belongs to
     * @param PlaidSecurity $security The security for this holding
     * @param array $holdingData Raw holding data from Plaid
     * @return self
     */
    public static function createOrUpdateFromPlaid(
        PlaidAccount $plaidAccount,
        PlaidSecurity $security,
        array $holdingData
    ): self {
        return self::updateOrCreate(
            [
                'plaid_account_id' => $plaidAccount->id,
                'plaid_security_id' => $security->id,
            ],
            [
                'plaid_account_identifier' => $holdingData['account_id'],
                'quantity' => $holdingData['quantity'] ?? 0,
                'cost_basis_cents' => isset($holdingData['cost_basis'])
                    ? (int) round($holdingData['cost_basis'] * 100)
                    : null,
                'institution_price_cents' => isset($holdingData['institution_price'])
                    ? (int) round($holdingData['institution_price'] * 100)
                    : null,
                'institution_price_as_of' => $holdingData['institution_price_as_of'] ?? null,
                'institution_value_cents' => isset($holdingData['institution_value'])
                    ? (int) round($holdingData['institution_value'] * 100)
                    : null,
                'iso_currency_code' => $holdingData['iso_currency_code'] ?? null,
                'unofficial_currency_code' => $holdingData['unofficial_currency_code'] ?? null,
                'original_data' => $holdingData,
            ]
        );
    }
}
