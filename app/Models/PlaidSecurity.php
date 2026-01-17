<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaidSecurity extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'plaid_security_id',
        'ticker_symbol',
        'name',
        'type',
        'isin',
        'cusip',
        'sedol',
        'close_price_cents',
        'close_price_as_of',
        'iso_currency_code',
        'unofficial_currency_code',
        'is_cash_equivalent',
        'original_data',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'close_price_cents' => 'integer',
        'close_price_as_of' => 'date',
        'is_cash_equivalent' => 'boolean',
        'original_data' => 'array',
    ];

    /**
     * Get the holdings that reference this security.
     */
    public function holdings(): HasMany
    {
        return $this->hasMany(PlaidHolding::class);
    }

    /**
     * Get the close price in dollars.
     */
    public function getClosePriceAttribute(): ?float
    {
        return $this->close_price_cents ? $this->close_price_cents / 100 : null;
    }

    /**
     * Create or update a security from Plaid API data.
     *
     * @param array $securityData Raw security data from Plaid
     * @return self
     */
    public static function createOrUpdateFromPlaid(array $securityData): self
    {
        return self::updateOrCreate(
            [
                'plaid_security_id' => $securityData['security_id'],
            ],
            [
                'ticker_symbol' => $securityData['ticker_symbol'] ?? null,
                'name' => $securityData['name'] ?? null,
                'type' => $securityData['type'] ?? null,
                'isin' => $securityData['isin'] ?? null,
                'cusip' => $securityData['cusip'] ?? null,
                'sedol' => $securityData['sedol'] ?? null,
                'close_price_cents' => isset($securityData['close_price'])
                    ? (int) round($securityData['close_price'] * 100)
                    : null,
                'close_price_as_of' => $securityData['close_price_as_of'] ?? null,
                'iso_currency_code' => $securityData['iso_currency_code'] ?? null,
                'unofficial_currency_code' => $securityData['unofficial_currency_code'] ?? null,
                'is_cash_equivalent' => $securityData['is_cash_equivalent'] ?? false,
                'original_data' => $securityData,
            ]
        );
    }
}
